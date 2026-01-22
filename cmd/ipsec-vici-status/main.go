package main

import (
    "context"
    "encoding/json"
    "fmt"
    "log"
    "os"
    "sort"
    "time"

    "github.com/strongswan/govici/vici"
)

type ChildStatus struct {
    Name       string   `json:"name"`
    State      string   `json:"state"`
    LocalTS    []string `json:"local_ts"`
    RemoteTS   []string `json:"remote_ts"`
    BytesIn    uint64   `json:"bytes_in"`
    BytesOut   uint64   `json:"bytes_out"`
    PacketsIn  uint64   `json:"packets_in"`
    PacketsOut uint64   `json:"packets_out"`
}

type Tunnel struct {
    Name          string        `json:"name"`
    PeerIP        *string       `json:"peer_ip"`
    PeerFQDN      *string       `json:"peer_fqdn"`
    IKEState      string        `json:"ike_state"`
    Since         *string       `json:"since"`
    Children      []ChildStatus `json:"children"`
    BytesIn       uint64        `json:"bytes_in"`
    BytesOut      uint64        `json:"bytes_out"`
    PacketsIn     uint64        `json:"packets_in"`
    PacketsOut    uint64        `json:"packets_out"`
    LocalSubnets  []string      `json:"local_subnets"`
    RemoteSubnets []string      `json:"remote_subnets"`
    Flaps24h      uint64        `json:"flaps_24h"`
}

type Status struct {
    Tunnels []Tunnel `json:"tunnels"`
}

func strPtr(s string) *string {
    if s == "" {
        return nil
    }
    return &s
}

func main() {
    ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
    defer cancel()

    sess, err := vici.NewSession()
    if err != nil {
        log.Fatalf("NewSession failed: %v", err)
    }
    defer sess.Close()

    tunnels := make(map[string]*Tunnel)

    // 1) list-conns → skeleton tunelů (statická konfigurace)
    if err := loadConns(ctx, sess, tunnels); err != nil {
        log.Fatalf("loadConns failed: %v", err)
    }

    // 2) list-sas → runtime (UP/DOWN, peer_ip, since, children, counters)
    if err := loadSAs(ctx, sess, tunnels); err != nil {
        log.Fatalf("loadSAs failed: %v", err)
    }

    // 3) poskládat slice, seřadit podle jména a vypsat JSON
    var out Status
    for _, t := range tunnels {
        out.Tunnels = append(out.Tunnels, *t)
    }
    sort.Slice(out.Tunnels, func(i, j int) bool {
        return out.Tunnels[i].Name < out.Tunnels[j].Name
    })

    enc := json.NewEncoder(os.Stdout)
    enc.SetEscapeHTML(false)
    if err := enc.Encode(out); err != nil {
        log.Fatalf("encode failed: %v", err)
    }
}

func loadConns(ctx context.Context, sess *vici.Session, tunnels map[string]*Tunnel) error {
    in := vici.NewMessage()
    iter := sess.CallStreaming(ctx, "list-conns", "list-conn", in)

    for msg, err := range iter {
        if err != nil {
            return err
        }

        // vrchní úroveň: klíče jsou jména IKE profilů (poruba, martin, ...)
        for _, name := range msg.Keys() {
            val := msg.Get(name)
            sub, ok := val.(*vici.Message)
            if !ok {
                continue
            }

            // peer_fqdn z remote-1.id
            var peerFQDN *string
            if remoteMsg, _ := sub.Get("remote-1").(*vici.Message); remoteMsg != nil {
                if idVal, ok := remoteMsg.Get("id").(string); ok && idVal != "" {
                    peerFQDN = &idVal
                }
            }

            // children → TS (local_subnets, remote_subnets)
            localSubs := []string{}
            remoteSubs := []string{}
            if childrenMsg, _ := sub.Get("children").(*vici.Message); childrenMsg != nil {
                for _, childName := range childrenMsg.Keys() {
                    childVal := childrenMsg.Get(childName)
                    if childSub, ok := childVal.(*vici.Message); ok {
                        if lts, ok := toStringSlice(childSub.Get("local-ts")); ok {
                            localSubs = appendUnique(localSubs, lts...)
                        }
                        if rts, ok := toStringSlice(childSub.Get("remote-ts")); ok {
                            remoteSubs = appendUnique(remoteSubs, rts...)
                        }
                    }
                }
            }

            if _, exists := tunnels[name]; !exists {
                tunnels[name] = &Tunnel{
                    Name:          name,
                    PeerIP:        nil,
                    PeerFQDN:      peerFQDN,
                    IKEState:      "DOWN",
                    Since:         nil,
                    Children:      []ChildStatus{},
                    BytesIn:       0,
                    BytesOut:      0,
                    PacketsIn:     0,
                    PacketsOut:    0,
                    LocalSubnets:  localSubs,
                    RemoteSubnets: remoteSubs,
                    Flaps24h:      0,
                }
            } else {
                // kdyby profil existoval, doplníme TS/FQDN
                t := tunnels[name]
                if t.PeerFQDN == nil && peerFQDN != nil {
                    t.PeerFQDN = peerFQDN
                }
                t.LocalSubnets = appendUnique(t.LocalSubnets, localSubs...)
                t.RemoteSubnets = appendUnique(t.RemoteSubnets, remoteSubs...)
            }
        }
    }

    return nil
}

func loadSAs(ctx context.Context, sess *vici.Session, tunnels map[string]*Tunnel) error {
    in := vici.NewMessage()
    iter := sess.CallStreaming(ctx, "list-sas", "list-sa", in)

    for msg, err := range iter {
        if err != nil {
            return err
        }

        // vrchní úroveň: klíče jsou jména IKE SA (copservis, martin, ...)
        for _, name := range msg.Keys() {
            val := msg.Get(name)
            ike, ok := val.(*vici.Message)
            if !ok {
                continue
            }

            t, exists := tunnels[name]
            if !exists {
                t = &Tunnel{
                    Name:          name,
                    IKEState:      "DOWN",
                    Children:      []ChildStatus{},
                    LocalSubnets:  []string{},
                    RemoteSubnets: []string{},
                }
                tunnels[name] = t
            }

            // state → IKEState
            if state, ok := ike.Get("state").(string); ok {
                if state == "ESTABLISHED" {
                    t.IKEState = "UP"
                } else {
                    t.IKEState = state
                }
            }

            // peer IP
            if rh, ok := ike.Get("remote-host").(string); ok && rh != "" {
                t.PeerIP = &rh
            }

            // since (established v sekundách → string)
            if estStr, ok := ike.Get("established").(string); ok && estStr != "" {
                if secs, err := parseUint(estStr); err == nil {
                    s := fmt.Sprintf("%d seconds ago", secs)
                    t.Since = &s
                }
            }

            // child-sas
            if csMsg, _ := ike.Get("child-sas").(*vici.Message); csMsg != nil {
                for _, childName := range csMsg.Keys() {
                    childVal := csMsg.Get(childName)
                    child, ok := childVal.(*vici.Message)
                    if !ok {
                        continue
                    }

                    var cs ChildStatus

                    if nameStr, ok := child.Get("name").(string); ok {
                        cs.Name = nameStr
                    } else {
                        cs.Name = childName
                    }

                    if st, ok := child.Get("state").(string); ok {
                        cs.State = st
                    }

                    if lts, ok := toStringSlice(child.Get("local-ts")); ok {
                        cs.LocalTS = lts
                        t.LocalSubnets = appendUnique(t.LocalSubnets, lts...)
                    }

                    if rts, ok := toStringSlice(child.Get("remote-ts")); ok {
                        cs.RemoteTS = rts
                        t.RemoteSubnets = appendUnique(t.RemoteSubnets, rts...)
                    }

                    if v, ok := child.Get("bytes-in").(string); ok {
                        cs.BytesIn, _ = parseUint(v)
                        t.BytesIn += cs.BytesIn
                    }
                    if v, ok := child.Get("bytes-out").(string); ok {
                        cs.BytesOut, _ = parseUint(v)
                        t.BytesOut += cs.BytesOut
                    }
                    if v, ok := child.Get("packets-in").(string); ok {
                        cs.PacketsIn, _ = parseUint(v)
                        t.PacketsIn += cs.PacketsIn
                    }
                    if v, ok := child.Get("packets-out").(string); ok {
                        cs.PacketsOut, _ = parseUint(v)
                        t.PacketsOut += cs.PacketsOut
                    }

                    t.Children = append(t.Children, cs)
                }
            }
        }
    }

    return nil
}

func toStringSlice(v any) ([]string, bool) {
    if v == nil {
        return nil, false
    }
    if s, ok := v.(string); ok {
        if s == "" {
            return nil, false
        }
        return []string{s}, true
    }
    if ss, ok := v.([]string); ok {
        if len(ss) == 0 {
            return nil, false
        }
        return ss, true
    }
    return nil, false
}

func appendUnique(dst []string, src ...string) []string {
    exists := make(map[string]struct{}, len(dst))
    for _, v := range dst {
        exists[v] = struct{}{}
    }
    for _, v := range src {
        if _, ok := exists[v]; !ok {
            dst = append(dst, v)
            exists[v] = struct{}{}
        }
    }
    return dst
}

func parseUint(s string) (uint64, error) {
    var n uint64
    for i := 0; i < len(s); i++ {
        c := s[i]
        if c < '0' || c > '9' {
            return 0, fmt.Errorf("invalid digit in %q", s)
        }
        n = n*10 + uint64(c-'0')
    }
    return n, nil
}
