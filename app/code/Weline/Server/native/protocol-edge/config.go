package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"
)

type edgeConfig struct {
	SchemaVersion int             `json:"schema_version"`
	Instance      string          `json:"instance"`
	Public        publicConfig    `json:"public"`
	AdminAddress  string          `json:"admin_address"`
	Protocols     []string        `json:"protocols"`
	Preferred     string          `json:"preferred"`
	AltSvc        bool            `json:"alt_svc"`
	TLS           tlsConfig       `json:"tls"`
	Proxy         proxyConfig     `json:"proxy"`
	Timeouts      timeoutConfig   `json:"timeouts"`
	Limits        protocolLimits  `json:"limits"`
	KeepAlive     keepAliveConfig `json:"keep_alive"`
	Digest        string          `json:"-"`
}

type publicConfig struct {
	Address string `json:"address"`
	Host    string `json:"host"`
	Port    int    `json:"port"`
}

type tlsConfig struct {
	CertificateFile       string `json:"certificate_file"`
	PrivateKeyFile        string `json:"private_key_file"`
	MinimumVersion        string `json:"minimum_version"`
	MaximumVersion        string `json:"maximum_version"`
	KeyExchangeProfile    string `json:"key_exchange_profile"`
	SessionResumption     bool   `json:"session_resumption"`
	SessionTicketKeyFile  string `json:"session_ticket_key_file"`
	SessionTicketRotation string `json:"session_ticket_rotation"`
	SessionTicketMaxKeys  int    `json:"session_ticket_max_keys"`
}

type proxyConfig struct {
	Upstreams             []string `json:"upstreams"`
	TokenFile             string   `json:"token_file"`
	HealthHost            string   `json:"health_host"`
	HealthPath            string   `json:"health_path"`
	HealthInterval        string   `json:"health_interval"`
	HealthTimeout         string   `json:"health_timeout"`
	DialTimeout           string   `json:"dial_timeout"`
	ResponseHeaderTimeout string   `json:"response_header_timeout"`
	IdleConnectionTimeout string   `json:"idle_connection_timeout"`
	MaxIdleConnections    int      `json:"max_idle_connections"`
	MaxIdlePerUpstream    int      `json:"max_idle_per_upstream"`
	MaxConnectionsPerHost int      `json:"max_connections_per_upstream"`
}

type timeoutConfig struct {
	ReadHeader string `json:"read_header"`
	ReadBody   string `json:"read_body"`
	Write      string `json:"write"`
	Idle       string `json:"idle"`
}

type protocolLimits struct {
	MaxHeaderBytes int64 `json:"max_header_bytes"`
}

type keepAliveConfig struct {
	TCPInterval string `json:"tcp_interval"`
	QUICIdle    string `json:"quic_idle"`
}

type parsedDurations struct {
	readHeader            time.Duration
	readBody              time.Duration
	write                 time.Duration
	idle                  time.Duration
	healthInterval        time.Duration
	healthTimeout         time.Duration
	dialTimeout           time.Duration
	responseHeaderTimeout time.Duration
	idleConnectionTimeout time.Duration
	tcpInterval           time.Duration
	quicIdle              time.Duration
	ticketRotation        time.Duration
}

type loadedConfig struct {
	config    edgeConfig
	durations parsedDurations
	token     string
}

func loadConfig(path string) (*loadedConfig, error) {
	payload, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read config: %w", err)
	}
	var cfg edgeConfig
	decoder := json.NewDecoder(strings.NewReader(string(payload)))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&cfg); err != nil {
		return nil, fmt.Errorf("decode config: %w", err)
	}
	digest := sha256.Sum256(payload)
	cfg.Digest = hex.EncodeToString(digest[:])
	if err := validateConfig(&cfg); err != nil {
		return nil, err
	}
	durations, err := parseDurations(cfg)
	if err != nil {
		return nil, err
	}
	tokenBytes, err := os.ReadFile(cfg.Proxy.TokenFile)
	if err != nil {
		return nil, fmt.Errorf("read edge token: %w", err)
	}
	token := strings.ToLower(strings.TrimSpace(string(tokenBytes)))
	if len(token) != 64 {
		return nil, errors.New("edge token must be a 64-character hexadecimal value")
	}
	if _, err := hex.DecodeString(token); err != nil {
		return nil, errors.New("edge token is not hexadecimal")
	}

	return &loadedConfig{config: cfg, durations: durations, token: token}, nil
}

func validateConfig(cfg *edgeConfig) error {
	if cfg.SchemaVersion != 1 {
		return fmt.Errorf("unsupported schema_version %d", cfg.SchemaVersion)
	}
	if cfg.Instance == "" {
		return errors.New("instance is required")
	}
	if err := validateListenAddress(cfg.Public.Address, false); err != nil {
		return fmt.Errorf("public.address: %w", err)
	}
	if err := validateListenAddress(cfg.AdminAddress, true); err != nil {
		return fmt.Errorf("admin_address: %w", err)
	}
	if cfg.Public.Port < 1 || cfg.Public.Port > 65535 {
		return errors.New("public.port is outside the valid range")
	}
	if cfg.Public.Host == "" {
		return errors.New("public.host is required")
	}
	if len(cfg.Protocols) == 0 {
		return errors.New("at least one HTTP protocol is required")
	}
	seen := map[string]bool{}
	for _, protocol := range cfg.Protocols {
		if protocol != "h1" && protocol != "h2" && protocol != "h3" {
			return fmt.Errorf("unsupported HTTP protocol %q", protocol)
		}
		if seen[protocol] {
			return fmt.Errorf("duplicate HTTP protocol %q", protocol)
		}
		seen[protocol] = true
	}
	if !seen[cfg.Preferred] {
		return errors.New("preferred protocol is not enabled")
	}
	if !seen["h1"] && !seen["h2"] {
		return errors.New("the TCP listener requires h1 or h2")
	}
	if !filepath.IsAbs(cfg.TLS.CertificateFile) || !filepath.IsAbs(cfg.TLS.PrivateKeyFile) {
		return errors.New("TLS certificate and key paths must be absolute")
	}
	for _, path := range []string{cfg.TLS.CertificateFile, cfg.TLS.PrivateKeyFile} {
		info, err := os.Stat(path)
		if err != nil || !info.Mode().IsRegular() {
			return fmt.Errorf("TLS file is unreadable: %s", path)
		}
	}
	if cfg.TLS.MinimumVersion != "tls1.2" && cfg.TLS.MinimumVersion != "tls1.3" {
		return errors.New("TLS minimum_version must be tls1.2 or tls1.3")
	}
	if cfg.TLS.MaximumVersion != "tls1.2" && cfg.TLS.MaximumVersion != "tls1.3" {
		return errors.New("TLS maximum_version must be tls1.2 or tls1.3")
	}
	if cfg.TLS.MinimumVersion == "tls1.3" && cfg.TLS.MaximumVersion == "tls1.2" {
		return errors.New("TLS version range is inverted")
	}
	if cfg.TLS.KeyExchangeProfile == "" {
		cfg.TLS.KeyExchangeProfile = "performance"
	}
	if cfg.TLS.KeyExchangeProfile != "performance" && cfg.TLS.KeyExchangeProfile != "system" {
		return errors.New("TLS key_exchange_profile must be performance or system")
	}
	if cfg.TLS.SessionResumption && !filepath.IsAbs(cfg.TLS.SessionTicketKeyFile) {
		return errors.New("session_ticket_key_file must be absolute")
	}
	if cfg.TLS.SessionTicketMaxKeys < 1 || cfg.TLS.SessionTicketMaxKeys > 8 {
		return errors.New("session_ticket_max_keys must be between 1 and 8")
	}
	if len(cfg.Proxy.Upstreams) == 0 {
		return errors.New("at least one upstream is required")
	}
	for _, upstream := range cfg.Proxy.Upstreams {
		parsed, err := url.Parse("http://" + upstream)
		if err != nil || parsed.Host != upstream || parsed.Port() == "" {
			return fmt.Errorf("invalid upstream %q", upstream)
		}
		host := parsed.Hostname()
		if host != "127.0.0.1" && host != "::1" && host != "localhost" {
			return fmt.Errorf("upstream must be loopback-only: %q", upstream)
		}
	}
	if !filepath.IsAbs(cfg.Proxy.TokenFile) {
		return errors.New("proxy.token_file must be absolute")
	}
	if cfg.Limits.MaxHeaderBytes < 4096 || cfg.Limits.MaxHeaderBytes > 1048576 {
		return errors.New("max_header_bytes must be between 4 KiB and 1 MiB")
	}
	if cfg.Proxy.MaxIdleConnections < 1 || cfg.Proxy.MaxIdlePerUpstream < 1 || cfg.Proxy.MaxConnectionsPerHost < 1 {
		return errors.New("proxy connection limits must be positive")
	}
	return nil
}

func validateListenAddress(address string, loopbackOnly bool) error {
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		return err
	}
	if port == "" {
		return errors.New("port is required")
	}
	if loopbackOnly {
		ip := net.ParseIP(host)
		if host != "localhost" && (ip == nil || !ip.IsLoopback()) {
			return errors.New("must use a loopback address")
		}
	}
	return nil
}

func parseDurations(cfg edgeConfig) (parsedDurations, error) {
	values := []struct {
		name   string
		value  string
		target *time.Duration
	}{
		{"timeouts.read_header", cfg.Timeouts.ReadHeader, nil},
		{"timeouts.read_body", cfg.Timeouts.ReadBody, nil},
		{"timeouts.write", cfg.Timeouts.Write, nil},
		{"timeouts.idle", cfg.Timeouts.Idle, nil},
		{"proxy.health_interval", cfg.Proxy.HealthInterval, nil},
		{"proxy.health_timeout", cfg.Proxy.HealthTimeout, nil},
		{"proxy.dial_timeout", cfg.Proxy.DialTimeout, nil},
		{"proxy.response_header_timeout", cfg.Proxy.ResponseHeaderTimeout, nil},
		{"proxy.idle_connection_timeout", cfg.Proxy.IdleConnectionTimeout, nil},
		{"keep_alive.tcp_interval", cfg.KeepAlive.TCPInterval, nil},
		{"keep_alive.quic_idle", cfg.KeepAlive.QUICIdle, nil},
		{"tls.session_ticket_rotation", cfg.TLS.SessionTicketRotation, nil},
	}
	parsed := make([]time.Duration, len(values))
	for index, value := range values {
		duration, err := time.ParseDuration(value.value)
		if err != nil || duration <= 0 {
			return parsedDurations{}, fmt.Errorf("%s must be a positive duration", value.name)
		}
		parsed[index] = duration
	}
	return parsedDurations{
		readHeader: parsed[0], readBody: parsed[1], write: parsed[2], idle: parsed[3],
		healthInterval: parsed[4], healthTimeout: parsed[5], dialTimeout: parsed[6],
		responseHeaderTimeout: parsed[7], idleConnectionTimeout: parsed[8],
		tcpInterval: parsed[9], quicIdle: parsed[10], ticketRotation: parsed[11],
	}, nil
}

func (cfg edgeConfig) supports(protocol string) bool {
	return slices.Contains(cfg.Protocols, protocol)
}

func immutableConfigEqual(previous, candidate edgeConfig) bool {
	return previous.Public.Address == candidate.Public.Address &&
		previous.Public.Port == candidate.Public.Port &&
		previous.AdminAddress == candidate.AdminAddress &&
		slices.Equal(previous.Protocols, candidate.Protocols) &&
		previous.Limits.MaxHeaderBytes == candidate.Limits.MaxHeaderBytes
}
