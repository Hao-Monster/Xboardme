package main

// Offline Go text/template compatibility probe; not Docker or a dependency.
import (
	"encoding/json"
	"fmt"
	"os"
	"text/template"
)

func main() {
	if len(os.Args) != 2 {
		os.Exit(2)
	}
	t, err := template.New("safe").Funcs(template.FuncMap{"json": func(v any) (string, error) { b, e := json.Marshal(v); return string(b), e }}).Parse(os.Args[1])
	if err != nil {
		fmt.Fprintln(os.Stderr, "TEMPLATE_FAIL")
		os.Exit(1)
	}
	var items []map[string]any
	if json.NewDecoder(os.Stdin).Decode(&items) != nil {
		fmt.Fprintln(os.Stderr, "TEMPLATE_FAIL")
		os.Exit(1)
	}
	for _, item := range items {
		if t.Execute(os.Stdout, item) != nil {
			fmt.Fprintln(os.Stderr, "TEMPLATE_FAIL")
			os.Exit(1)
		}
		fmt.Fprintln(os.Stdout)
	}
}
