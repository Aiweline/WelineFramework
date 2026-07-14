package main

import (
	"context"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

type upstream struct {
	authority string
	inFlight  atomic.Int64
	healthy   atomic.Bool
	failures  atomic.Uint64
}

type upstreamPool struct {
	items []*upstream
	next  atomic.Uint64
}

type selectedUpstreamKey struct{}

type selectedUpstream struct {
	upstream *upstream
	token    string
	protocol string
	clientIP string
	host     string
}

func newUpstreamPool(authorities []string) *upstreamPool {
	pool := &upstreamPool{items: make([]*upstream, 0, len(authorities))}
	for _, authority := range authorities {
		item := &upstream{authority: authority}
		item.healthy.Store(true)
		pool.items = append(pool.items, item)
	}
	return pool
}

func (pool *upstreamPool) acquire() (*upstream, error) {
	if pool == nil || len(pool.items) == 0 {
		return nil, errors.New("no upstreams are configured")
	}
	start := int(pool.next.Add(1) % uint64(len(pool.items)))
	var selected *upstream
	for offset := 0; offset < len(pool.items); offset++ {
		candidate := pool.items[(start+offset)%len(pool.items)]
		if !candidate.healthy.Load() {
			continue
		}
		if selected == nil || candidate.inFlight.Load() < selected.inFlight.Load() {
			selected = candidate
		}
	}
	if selected == nil {
		selected = pool.items[start]
	}
	selected.inFlight.Add(1)
	return selected, nil
}

func (pool *upstreamPool) snapshot() []map[string]any {
	result := make([]map[string]any, 0, len(pool.items))
	for _, item := range pool.items {
		result = append(result, map[string]any{
			"address":   item.authority,
			"healthy":   item.healthy.Load(),
			"in_flight": item.inFlight.Load(),
			"failures":  item.failures.Load(),
		})
	}
	return result
}

type pooledBuffer struct {
	pool sync.Pool
}

func newPooledBuffer() *pooledBuffer {
	return &pooledBuffer{pool: sync.Pool{New: func() any {
		buffer := make([]byte, 32*1024)
		return &buffer
	}}}
}

func (pool *pooledBuffer) Get() []byte {
	return *pool.pool.Get().(*[]byte)
}

func (pool *pooledBuffer) Put(buffer []byte) {
	if cap(buffer) != 32*1024 {
		return
	}
	buffer = buffer[:32*1024]
	pool.pool.Put(&buffer)
}

func buildReverseProxy(initial *loadedConfig) (*httputil.ReverseProxy, *http.Transport) {
	transport := &http.Transport{
		Proxy:                 nil,
		DialContext:           (&net.Dialer{Timeout: initial.durations.dialTimeout, KeepAlive: initial.durations.tcpInterval}).DialContext,
		ForceAttemptHTTP2:     false,
		MaxIdleConns:          initial.config.Proxy.MaxIdleConnections,
		MaxIdleConnsPerHost:   initial.config.Proxy.MaxIdlePerUpstream,
		MaxConnsPerHost:       initial.config.Proxy.MaxConnectionsPerHost,
		IdleConnTimeout:       initial.durations.idleConnectionTimeout,
		ResponseHeaderTimeout: initial.durations.responseHeaderTimeout,
		ExpectContinueTimeout: time.Second,
		DisableCompression:    true,
	}
	proxy := &httputil.ReverseProxy{
		Transport:  transport,
		BufferPool: newPooledBuffer(),
		Rewrite: func(request *httputil.ProxyRequest) {
			selected, _ := request.In.Context().Value(selectedUpstreamKey{}).(selectedUpstream)
			request.Out.URL.Scheme = "http"
			request.Out.URL.Host = selected.upstream.authority
			request.Out.Host = selected.host
			request.Out.RequestURI = ""
			for _, header := range []string{
				"Forwarded", "X-Forwarded-For", "X-Forwarded-Host", "X-Forwarded-Proto",
				"X-WLS-Edge-Token", "X-WLS-Client-Protocol",
			} {
				request.Out.Header.Del(header)
			}
			request.Out.Header.Set("X-WLS-Edge-Token", selected.token)
			request.Out.Header.Set("X-WLS-Client-Protocol", selected.protocol)
			request.Out.Header.Set("X-Forwarded-For", selected.clientIP)
			request.Out.Header.Set("X-Forwarded-Proto", "https")
		},
		ErrorHandler: func(writer http.ResponseWriter, request *http.Request, err error) {
			if selected, ok := request.Context().Value(selectedUpstreamKey{}).(selectedUpstream); ok && selected.upstream != nil {
				selected.upstream.failures.Add(1)
				selected.upstream.healthy.Store(false)
			}
			writer.Header().Set("Content-Type", "text/plain; charset=utf-8")
			writer.WriteHeader(http.StatusBadGateway)
			_, _ = io.WriteString(writer, "Bad Gateway\n")
		},
	}
	return proxy, transport
}

func clientIP(request *http.Request) string {
	host, _, err := net.SplitHostPort(request.RemoteAddr)
	if err == nil && host != "" {
		return host
	}
	if value, ok := request.Context().Value(http.LocalAddrContextKey).(net.Addr); ok && value != nil {
		_ = value
	}
	return strings.Trim(request.RemoteAddr, "[]")
}

func protocolName(request *http.Request) string {
	if request.ProtoMajor >= 3 {
		return "HTTP/3"
	}
	if request.ProtoMajor == 2 {
		return "HTTP/2"
	}
	return "HTTP/1.1"
}

func healthRequest(ctx context.Context, state *runtimeState, item *upstream) bool {
	endpoint := url.URL{Scheme: "http", Host: item.authority, Path: state.loaded.config.Proxy.HealthPath}
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint.String(), nil)
	if err != nil {
		return false
	}
	request.Host = state.loaded.config.Proxy.HealthHost
	request.Header.Set("X-WLS-Edge-Token", state.loaded.token)
	request.Header.Set("X-WLS-Client-Protocol", "HTTP/1.1")
	request.Header.Set("Connection", "close")
	client := http.Client{Transport: &http.Transport{
		Proxy:                 nil,
		DialContext:           (&net.Dialer{Timeout: state.loaded.durations.healthTimeout}).DialContext,
		DisableKeepAlives:     true,
		DisableCompression:    true,
		ResponseHeaderTimeout: state.loaded.durations.healthTimeout,
	}}
	response, err := client.Do(request)
	if err != nil {
		return false
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4096))
	_ = response.Body.Close()
	return response.StatusCode >= 200 && response.StatusCode < 300
}
