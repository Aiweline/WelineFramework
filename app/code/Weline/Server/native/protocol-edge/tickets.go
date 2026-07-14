package main

import (
	"crypto/rand"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

type ticketKeyRing struct {
	SchemaVersion int      `json:"schema_version"`
	RotatedAt     int64    `json:"rotated_at"`
	Keys          []string `json:"keys"`
}

func configureTicketKeys(config *tls.Config, cfg tlsConfig, rotation time.Duration) error {
	if !cfg.SessionResumption {
		config.SessionTicketsDisabled = true
		return nil
	}
	keys, err := loadOrRotateTicketKeys(cfg.SessionTicketKeyFile, rotation, cfg.SessionTicketMaxKeys)
	if err != nil {
		return err
	}
	config.SetSessionTicketKeys(keys)
	return nil
}

func loadOrRotateTicketKeys(path string, rotation time.Duration, maxKeys int) ([][32]byte, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return nil, fmt.Errorf("create ticket key directory: %w", err)
	}
	ring := ticketKeyRing{SchemaVersion: 1}
	payload, err := os.ReadFile(path)
	if err == nil {
		if err := json.Unmarshal(payload, &ring); err != nil || ring.SchemaVersion != 1 {
			return nil, errors.New("session ticket key ring is invalid")
		}
	} else if !os.IsNotExist(err) {
		return nil, fmt.Errorf("read session ticket key ring: %w", err)
	}

	now := time.Now()
	rotate := len(ring.Keys) == 0 || ring.RotatedAt <= 0 || now.Sub(time.Unix(ring.RotatedAt, 0)) >= rotation
	if rotate {
		key := make([]byte, 32)
		if _, err := rand.Read(key); err != nil {
			return nil, fmt.Errorf("generate session ticket key: %w", err)
		}
		ring.Keys = append([]string{base64.StdEncoding.EncodeToString(key)}, ring.Keys...)
		if len(ring.Keys) > maxKeys {
			ring.Keys = ring.Keys[:maxKeys]
		}
		ring.RotatedAt = now.Unix()
		if err := writeTicketKeyRing(path, ring); err != nil {
			return nil, err
		}
	}

	keys := make([][32]byte, 0, len(ring.Keys))
	for _, encoded := range ring.Keys {
		decoded, err := base64.StdEncoding.DecodeString(encoded)
		if err != nil || len(decoded) != 32 {
			return nil, errors.New("session ticket key ring contains an invalid key")
		}
		var key [32]byte
		copy(key[:], decoded)
		keys = append(keys, key)
	}
	if len(keys) == 0 {
		return nil, errors.New("session ticket key ring is empty")
	}
	return keys, nil
}

func writeTicketKeyRing(path string, ring ticketKeyRing) error {
	payload, err := json.Marshal(ring)
	if err != nil {
		return fmt.Errorf("encode session ticket key ring: %w", err)
	}
	temporary := fmt.Sprintf("%s.tmp-%d", path, time.Now().UnixNano())
	if err := os.WriteFile(temporary, append(payload, '\n'), 0600); err != nil {
		return fmt.Errorf("write session ticket key ring: %w", err)
	}
	if err := os.Rename(temporary, path); err != nil {
		_ = os.Remove(temporary)
		return fmt.Errorf("publish session ticket key ring: %w", err)
	}
	return os.Chmod(path, 0600)
}
