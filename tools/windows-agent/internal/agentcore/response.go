package agentcore

import (
	"encoding/json"
	"fmt"
	"os"
)

type Response struct {
	Success bool           `json:"success"`
	Code    string         `json:"code"`
	Message string         `json:"message"`
	Data    map[string]any `json:"data"`
}

func OK(code string, message string, data map[string]any) {
	Exit(Response{Success: true, Code: code, Message: message, Data: data}, 0)
}

func Fail(code string, message string, exitCode int) {
	if exitCode == 0 {
		exitCode = 1
	}
	Exit(Response{Success: false, Code: code, Message: message, Data: map[string]any{}}, exitCode)
}

func Failf(code string, exitCode int, format string, args ...any) {
	Fail(code, fmt.Sprintf(format, args...), exitCode)
}

func Exit(response Response, exitCode int) {
	encoder := json.NewEncoder(os.Stdout)
	encoder.SetEscapeHTML(false)
	_ = encoder.Encode(response)
	os.Exit(exitCode)
}
