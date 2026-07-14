package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"net"
	"os"
	"os/signal"
	"runtime"
	"syscall"
	"time"
)

var (
	buildVersion      = "2.0.0-dev"
	buildCommit       = "unknown"
	buildSourceDigest = "unknown"
)

type capabilityReport struct {
	Name         string   `json:"name"`
	Version      string   `json:"version"`
	Commit       string   `json:"commit"`
	SourceDigest string   `json:"source_digest"`
	GoVersion    string   `json:"go_version"`
	OS           string   `json:"os"`
	Arch         string   `json:"arch"`
	Protocols    []string `json:"protocols"`
	TLS          []string `json:"tls"`
	Features     []string `json:"features"`
	ProbeOK      bool     `json:"probe_ok,omitempty"`
	ProbeError   string   `json:"probe_error,omitempty"`
}

func main() {
	if len(os.Args) < 2 {
		fatalf("usage: wls-protocol-edge <version|probe|check|serve>")
	}

	switch os.Args[1] {
	case "version":
		writeJSON(capabilities(false, ""))
	case "probe":
		err := probeSockets()
		report := capabilities(err == nil, errorString(err))
		writeJSON(report)
		if err != nil {
			os.Exit(1)
		}
	case "check":
		configPath := requiredConfigFlag("check", os.Args[2:])
		if _, err := loadConfig(configPath); err != nil {
			fatalf("configuration rejected: %v", err)
		}
		writeJSON(map[string]any{"valid": true, "config": configPath})
	case "serve":
		configPath := requiredConfigFlag("serve", os.Args[2:])
		ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
		defer cancel()
		if err := runEdge(ctx, configPath); err != nil && !errors.Is(err, context.Canceled) {
			fatalf("protocol edge stopped: %v", err)
		}
	default:
		fatalf("unknown command %q", os.Args[1])
	}
}

func requiredConfigFlag(command string, arguments []string) string {
	set := flag.NewFlagSet(command, flag.ContinueOnError)
	set.SetOutput(os.Stderr)
	configPath := set.String("config", "", "immutable WLS protocol-edge configuration")
	if err := set.Parse(arguments); err != nil {
		os.Exit(2)
	}
	if *configPath == "" {
		fatalf("--config is required")
	}
	return *configPath
}

func capabilities(probeOK bool, probeError string) capabilityReport {
	return capabilityReport{
		Name:         "wls-protocol-edge",
		Version:      buildVersion,
		Commit:       buildCommit,
		SourceDigest: buildSourceDigest,
		GoVersion:    runtime.Version(),
		OS:           runtime.GOOS,
		Arch:         runtime.GOARCH,
		Protocols:    []string{"h3", "h2", "h1"},
		TLS:          []string{"tls1.3", "tls1.2"},
		Features:     []string{"quic", "alpn", "tls_session_tickets", "zero_rtt_safe_methods", "upstream_keepalive", "atomic_reload"},
		ProbeOK:      probeOK,
		ProbeError:   probeError,
	}
}

func probeSockets() error {
	tcp, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return fmt.Errorf("tcp listener: %w", err)
	}
	defer tcp.Close()
	port := tcp.Addr().(*net.TCPAddr).Port
	udp, err := net.ListenPacket("udp", fmt.Sprintf("127.0.0.1:%d", port))
	if err != nil {
		return fmt.Errorf("udp listener: %w", err)
	}
	defer udp.Close()
	if tls.VersionTLS13 == 0 {
		return errors.New("TLS 1.3 is unavailable")
	}
	return nil
}

func writeJSON(value any) {
	encoder := json.NewEncoder(os.Stdout)
	encoder.SetEscapeHTML(false)
	if err := encoder.Encode(value); err != nil {
		fatalf("encode output: %v", err)
	}
}

func fatalf(format string, arguments ...any) {
	fmt.Fprintf(os.Stderr, "wls-protocol-edge: "+format+"\n", arguments...)
	os.Exit(1)
}

func errorString(err error) string {
	if err == nil {
		return ""
	}
	return err.Error()
}

func unixMillis() int64 {
	return time.Now().UnixNano() / int64(time.Millisecond)
}
