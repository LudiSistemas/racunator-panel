# Licence biblioteka u `public/assets`

## qrcode-generator 1.4.4 (`qrcode.min.js`)

- Autor: Kazuhiko Arase — http://www.d-project.com/ , https://github.com/kazuhikoarase/qrcode-generator
- Licenca: MIT
- Verzija: 1.4.4 (npm paket `qrcode-generator`), pinovana; učitava se samo lokalno, bez CDN-a u radu.
- Izvor fajla: `https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js` — jsDelivr-ova Terser minifikacija
  originalnog `qrcode.js` iz istog paketa. Originalni `https://unpkg.com/qrcode-generator@1.4.4/qrcode.js` ima
  SHA-256 (base64) `GK45n4EYK8nekW6cd7GV3yDMWNby1VpisIWimfG/F4A=` (isti kao `integrity` u npm metapodacima).
- Izmene: na početak je dodat komentar sa autorskim pravima i licencom (minifikacija ga uklanja), a uklonjen je
  završni red `//# sourceMappingURL=…` (mapa ne postoji lokalno). Kod biblioteke nije menjan.
- Napomena: „QR Code" je registrovani zaštitni znak kompanije DENSO WAVE INCORPORATED.

```
The MIT License (MIT)

Copyright (c) 2009 Kazuhiko Arase

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

Ostali fajlovi u ovom folderu (`panel.css`, `panel.js`) deo su Računator panela (MIT, vidi `LICENSE` u korenu repoa).
