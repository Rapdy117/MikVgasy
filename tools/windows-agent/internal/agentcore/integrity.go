package agentcore

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
)

type IntegrityManifest struct {
	GeneratedAt string            `json:"generated_at"`
	Algorithm   string            `json:"algorithm"`
	Files       map[string]string `json:"files"`
}

func CheckIntegrity(appDir string) error {
	manifestPath := IntegrityManifestPath(appDir)
	bytes, err := os.ReadFile(manifestPath)
	if err != nil {
		return errors.New("INTEGRITY_MANIFEST_MISSING")
	}
	var manifest IntegrityManifest
	if err := json.Unmarshal(bytes, &manifest); err != nil {
		return errors.New("INTEGRITY_MANIFEST_INVALID")
	}
	if manifest.Algorithm != "" && manifest.Algorithm != "sha256" {
		return errors.New("INTEGRITY_ALGORITHM_UNSUPPORTED")
	}
	if len(manifest.Files) == 0 {
		return errors.New("INTEGRITY_MANIFEST_EMPTY")
	}
	for relativePath, expected := range manifest.Files {
		cleanRelative := filepath.Clean(filepath.FromSlash(relativePath))
		if strings.HasPrefix(cleanRelative, "..") || filepath.IsAbs(cleanRelative) {
			return errors.New("INTEGRITY_PATH_INVALID")
		}
		filePath := filepath.Join(appDir, cleanRelative)
		actual, err := sha256File(filePath)
		if err != nil {
			return errors.New("INTEGRITY_FILE_MISSING")
		}
		if !strings.EqualFold(strings.TrimPrefix(expected, "sha256:"), actual) {
			return errors.New("INTEGRITY_MISMATCH")
		}
	}
	return nil
}

func sha256File(path string) (string, error) {
	bytes, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(bytes)
	return hex.EncodeToString(sum[:]), nil
}
