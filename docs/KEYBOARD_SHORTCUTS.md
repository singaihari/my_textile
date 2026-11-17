# Keyboard shortcuts (POS)

| Key | Alternative | Action |
|---|---|---|
| F1 | Alt+1 | Help overlay |
| F2 | Alt+2 | New bill (asks: hold / discard / cancel if a bill is on screen) |
| F3 | Ctrl+K | Focus product search / scanner box |
| F4 | Alt+4 | Customer lookup / quick add (inter-state option inside) |
| F5 | Ctrl+D, Alt+5 | Bill discount (browser refresh is suppressed on the POS) |
| F6 | Alt+6 | Payment |
| F7 | Alt+7 | Hold (suspend) bill |
| F8 | Alt+8 | Resume held bill (↑↓, Enter, Del to discard) |
| F9 | Ctrl+R, Alt+9 | Return / exchange (offers to hold the current bill) |
| F10 | Ctrl+P, Alt+0, Ctrl+Enter | Confirm, save & print |
| Ctrl+H | | Recent bills on this counter (History) |
| ↓ from search | | Move into the product image cards |
| ← ↑ → ↓ | | Move between product cards (↑ from the top row returns to search) |
| PgUp / PgDn | | Previous / next category chip |
| ↑ from search | | Select the last cart line; ↑ ↓ move through cart lines |
| Enter | | Scan box: add scanned barcode · Card: add, or open the size / colour / design quick-pick · Cart line: edit · Dialog: confirm |
| In quick-pick | | ← → choose colour / size / design · ↑ ↓ switch row · fabric: type metres, Enter |
| + / − | | Quantity of selected line (pieces ±1, metres ±0.25) |
| Del | | Remove selected line (confirm; Ctrl+Z undo within 15 s) |
| Esc | | Close dialog / clear search text — never clears the bill |
| Alt+1…Alt+4 | | In payment: Cash, UPI, Card, Credit |
| Alt+S, = | | In payment: split payment, exact amount |
| R, A, Enter | | After save: retry print, A4 copy, next bill |
| Ctrl+P | | Invoice page: logged reprint |
| / | | List pages: focus search |

Fastest sale: **scan → scan → F6 → type cash received → F10 → Enter**.

Browser notes: some browsers reserve F-keys (F6 address bar, F10 menu bar, F11 full screen, F7 caret browsing prompt). The POS prevents
the default action where the browser allows it; when it does not, use the Alt alternative. Keys can be remapped in Settings → Shortcuts.
Verified with automated tests in headless Chrome (see TESTING.md); please also try them on the actual shop PC/browser.
