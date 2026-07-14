package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"net/http/httputil"
	"os"
	"sync"
	"sync/atomic"
	"time"

	quic "github.com/quic-go/quic-go"
	"github.com/quic-go/quic-go/http3"
)

type runtimeState struct {
	loaded    *loadedConfig
	pool      *upstreamPool
	tlsConfig *tls.Config
	loadedAt  time.Time
}

type edgeRuntime struct {
	configPath string
	startedAt  time.Time
	state      atomic.Pointer[runtimeState]
	reloadMu   sync.Mutex
	proxy      *httputil.ReverseProxy
	transport  *http.Transport
	tcpServer  *http.Server
	h3Server   *http3.Server
	admin      *http.Server
	requestID  atomic.Uint64
	requests   atomic.Uint64
	errors     atomic.Uint64
}

func runEdge(ctx context.Context, configPath string) error {
	loaded, err := loadConfig(configPath)
	if err != nil {
		return err
	}
	runtime, err := newEdgeRuntime(configPath, loaded)
	if err != nil {
		return err
	}
	return runtime.serve(ctx)
}

func newEdgeRuntime(configPath string, loaded *loadedConfig) (*edgeRuntime, error) {
	state, err := buildRuntimeState(loaded)
	if err != nil {
		return nil, err
	}
	proxy, transport := buildReverseProxy(loaded)
	runtime := &edgeRuntime{
		configPath: configPath,
		startedAt:  time.Now(),
		transport:  transport,
	}
	runtime.proxy = proxy
	runtime.state.Store(state)
	return runtime, nil
}

func buildRuntimeState(loaded *loadedConfig) (*runtimeState, error) {
	certificate, err := tls.LoadX509KeyPair(
		loaded.config.TLS.CertificateFile,
		loaded.config.TLS.PrivateKeyFile,
	)
	if err != nil {
		return nil, fmt.Errorf("load TLS certificate: %w", err)
	}
	tlsProfile := &tls.Config{
		Certificates:     []tls.Certificate{certificate},
		MinVersion:       tlsVersion(loaded.config.TLS.MinimumVersion),
		MaxVersion:       tlsVersion(loaded.config.TLS.MaximumVersion),
		CurvePreferences: tlsCurvePreferences(loaded.config.TLS.KeyExchangeProfile),
		NextProtos:       tcpALPN(loaded.config),
	}
	if err := configureTicketKeys(tlsProfile, loaded.config.TLS, loaded.durations.ticketRotation); err != nil {
		return nil, err
	}
	return &runtimeState{
		loaded:    loaded,
		pool:      newUpstreamPool(loaded.config.Proxy.Upstreams),
		tlsConfig: tlsProfile,
		loadedAt:  time.Now(),
	}, nil
}

func (runtime *edgeRuntime) serve(ctx context.Context) error {
	state := runtime.state.Load()
	if state == nil {
		return errors.New("runtime state is unavailable")
	}
	publicListener, err := net.Listen("tcp", state.loaded.config.Public.Address)
	if err != nil {
		return fmt.Errorf("listen public TCP: %w", err)
	}
	defer publicListener.Close()

	adminListener, err := net.Listen("tcp", state.loaded.config.AdminAddress)
	if err != nil {
		return fmt.Errorf("listen admin TCP: %w", err)
	}
	defer adminListener.Close()

	dynamicTLS := &tls.Config{
		MinVersion: tls.VersionTLS12,
		MaxVersion: tls.VersionTLS13,
		NextProtos: tcpALPN(state.loaded.config),
		GetConfigForClient: func(*tls.ClientHelloInfo) (*tls.Config, error) {
			current := runtime.state.Load()
			if current == nil {
				return nil, errors.New("TLS runtime state is unavailable")
			}
			return current.tlsConfig, nil
		},
	}
	runtime.tcpServer = &http.Server{
		Handler:           runtime,
		TLSConfig:         dynamicTLS,
		ReadHeaderTimeout: state.loaded.durations.readHeader,
		ReadTimeout:       state.loaded.durations.readBody,
		WriteTimeout:      state.loaded.durations.write,
		IdleTimeout:       state.loaded.durations.idle,
		MaxHeaderBytes:    int(state.loaded.config.Limits.MaxHeaderBytes),
		ErrorLog:          slog.NewLogLogger(slog.NewJSONHandler(os.Stderr, nil), slog.LevelError),
	}

	runtime.admin = &http.Server{
		Handler:           runtime.adminHandler(),
		ReadHeaderTimeout: 2 * time.Second,
		ReadTimeout:       5 * time.Second,
		WriteTimeout:      5 * time.Second,
		IdleTimeout:       10 * time.Second,
		MaxHeaderBytes:    16 * 1024,
	}

	var udpListener net.PacketConn
	if state.loaded.config.supports("h3") {
		udpListener, err = net.ListenPacket("udp", state.loaded.config.Public.Address)
		if err != nil {
			return fmt.Errorf("listen public UDP: %w", err)
		}
		defer udpListener.Close()
		h3BaseTLS := &tls.Config{
			MinVersion: tls.VersionTLS13,
			MaxVersion: tls.VersionTLS13,
			GetConfigForClient: func(*tls.ClientHelloInfo) (*tls.Config, error) {
				current := runtime.state.Load()
				if current == nil {
					return nil, errors.New("QUIC TLS runtime state is unavailable")
				}
				return current.tlsConfig, nil
			},
		}
		runtime.h3Server = &http3.Server{
			Addr:           state.loaded.config.Public.Address,
			Port:           state.loaded.config.Public.Port,
			Handler:        runtime,
			TLSConfig:      http3.ConfigureTLSConfig(h3BaseTLS),
			QUICConfig:     &quic.Config{Allow0RTT: true, MaxIdleTimeout: state.loaded.durations.quicIdle, KeepAlivePeriod: state.loaded.durations.tcpInterval},
			MaxHeaderBytes: int(state.loaded.config.Limits.MaxHeaderBytes),
			IdleTimeout:    state.loaded.durations.quicIdle,
		}
	}

	errorsChannel := make(chan error, 3)
	go func() { errorsChannel <- normalizeServerError(runtime.admin.Serve(adminListener)) }()
	go func() { errorsChannel <- normalizeServerError(runtime.tcpServer.ServeTLS(publicListener, "", "")) }()
	if runtime.h3Server != nil {
		go func() { errorsChannel <- normalizeServerError(runtime.h3Server.Serve(udpListener)) }()
	}
	go runtime.healthLoop(ctx)

	writeJSON(map[string]any{
		"event": "ready", "pid": os.Getpid(), "digest": state.loaded.config.Digest,
		"public": state.loaded.config.Public.Address, "protocols": state.loaded.config.Protocols,
		"tls_min": state.loaded.config.TLS.MinimumVersion, "tls_max": state.loaded.config.TLS.MaximumVersion,
		"session_resumption": state.loaded.config.TLS.SessionResumption,
	})

	select {
	case <-ctx.Done():
	case err := <-errorsChannel:
		if err != nil {
			return err
		}
	}

	shutdownContext, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = runtime.admin.Shutdown(shutdownContext)
	_ = runtime.tcpServer.Shutdown(shutdownContext)
	if runtime.h3Server != nil {
		_ = runtime.h3Server.Shutdown(shutdownContext)
	}
	runtime.transport.CloseIdleConnections()
	return ctx.Err()
}

func (runtime *edgeRuntime) ServeHTTP(writer http.ResponseWriter, request *http.Request) {
	runtime.requests.Add(1)
	state := runtime.state.Load()
	if state == nil {
		runtime.errors.Add(1)
		http.Error(writer, "Service Unavailable", http.StatusServiceUnavailable)
		return
	}
	if request.ProtoMajor >= 3 && request.TLS != nil && !request.TLS.HandshakeComplete && !safeZeroRTTMethod(request.Method) {
		writer.Header().Set("Retry-After", "0")
		http.Error(writer, "Too Early", http.StatusTooEarly)
		return
	}
	if state.loaded.config.AltSvc && state.loaded.config.supports("h3") && request.ProtoMajor < 3 {
		writer.Header().Set("Alt-Svc", fmt.Sprintf("h3=\":%d\"; ma=2592000", state.loaded.config.Public.Port))
	}
	upstream, err := state.pool.acquire()
	if err != nil {
		runtime.errors.Add(1)
		http.Error(writer, "Service Unavailable", http.StatusServiceUnavailable)
		return
	}
	defer upstream.inFlight.Add(-1)
	selected := selectedUpstream{
		upstream: upstream,
		token:    state.loaded.token,
		protocol: protocolName(request),
		clientIP: clientIP(request),
		host:     request.Host,
	}
	request = request.WithContext(context.WithValue(request.Context(), selectedUpstreamKey{}, selected))
	runtime.proxy.ServeHTTP(writer, request)
}

func (runtime *edgeRuntime) adminHandler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /_wls/edge/health", func(writer http.ResponseWriter, request *http.Request) {
		writer.Header().Set("Content-Type", "application/json")
		_, _ = writer.Write([]byte("{\"ok\":true}\n"))
	})
	mux.HandleFunc("GET /_wls/edge/state", func(writer http.ResponseWriter, request *http.Request) {
		state := runtime.state.Load()
		if state == nil {
			http.Error(writer, "state unavailable", http.StatusServiceUnavailable)
			return
		}
		writer.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(writer).Encode(map[string]any{
			"schema_version":         1,
			"config_digest":          state.loaded.config.Digest,
			"pid":                    os.Getpid(),
			"loaded_at":              state.loadedAt.UTC().Format(time.RFC3339Nano),
			"uptime_ms":              time.Since(runtime.startedAt).Milliseconds(),
			"protocols":              state.loaded.config.Protocols,
			"tls_minimum":            state.loaded.config.TLS.MinimumVersion,
			"tls_maximum":            state.loaded.config.TLS.MaximumVersion,
			"tls_session_resumption": state.loaded.config.TLS.SessionResumption,
			"requests":               runtime.requests.Load(),
			"errors":                 runtime.errors.Load(),
			"upstreams":              state.pool.snapshot(),
		})
	})
	mux.HandleFunc("POST /_wls/edge/reload", func(writer http.ResponseWriter, request *http.Request) {
		if err := runtime.reload(); err != nil {
			http.Error(writer, err.Error(), http.StatusConflict)
			return
		}
		state := runtime.state.Load()
		writer.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(writer).Encode(map[string]any{"reloaded": true, "config_digest": state.loaded.config.Digest})
	})
	return mux
}

func (runtime *edgeRuntime) reload() error {
	runtime.reloadMu.Lock()
	defer runtime.reloadMu.Unlock()
	candidate, err := loadConfig(runtime.configPath)
	if err != nil {
		return err
	}
	current := runtime.state.Load()
	if current == nil {
		return errors.New("current runtime state is unavailable")
	}
	if !immutableConfigEqual(current.loaded.config, candidate.config) {
		return errors.New("listener/protocol changes require a WLS restart")
	}
	next, err := buildRuntimeState(candidate)
	if err != nil {
		return err
	}
	runtime.state.Store(next)
	return nil
}

func (runtime *edgeRuntime) healthLoop(ctx context.Context) {
	for {
		state := runtime.state.Load()
		if state == nil {
			return
		}
		timer := time.NewTimer(state.loaded.durations.healthInterval)
		select {
		case <-ctx.Done():
			timer.Stop()
			return
		case <-timer.C:
		}
		state = runtime.state.Load()
		if state == nil {
			return
		}
		for _, item := range state.pool.items {
			probeContext, cancel := context.WithTimeout(ctx, state.loaded.durations.healthTimeout)
			healthy := healthRequest(probeContext, state, item)
			cancel()
			item.healthy.Store(healthy)
			if !healthy {
				item.failures.Add(1)
			}
		}
	}
}

func tcpALPN(config edgeConfig) []string {
	protocols := make([]string, 0, 2)
	if config.supports("h2") {
		protocols = append(protocols, "h2")
	}
	if config.supports("h1") {
		protocols = append(protocols, "http/1.1")
	}
	return protocols
}

func tlsVersion(version string) uint16 {
	if version == "tls1.3" {
		return tls.VersionTLS13
	}
	return tls.VersionTLS12
}

func tlsCurvePreferences(profile string) []tls.CurveID {
	if profile == "system" {
		return nil
	}

	return []tls.CurveID{tls.X25519, tls.CurveP256}
}

func safeZeroRTTMethod(method string) bool {
	return method == http.MethodGet || method == http.MethodHead || method == http.MethodOptions
}

func normalizeServerError(err error) error {
	if err == nil || errors.Is(err, http.ErrServerClosed) || errors.Is(err, net.ErrClosed) {
		return nil
	}
	return err
}
