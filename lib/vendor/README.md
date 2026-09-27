These are local copies of the theme dependencies previously loaded from CDNs, retrieved September 13, 2026. Preserve the copyright/license notices embedded in each file.

- GSAP 3.12.5: https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js
- ScrollTrigger 3.12.5: https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js
- Lenis 1.0.42: https://cdn.jsdelivr.net/npm/@studio-freight/lenis@1.0.42/dist/lenis.min.js

The templates use the existing local jQuery dependency rather than loading a second copy from a CDN. Fonts in ../fonts are local Google Fonts distributions of Syne, IBM Plex Mono, and Inter; their OFL license files are included there.

- qrcode-generator 1.4.4 (Kazuhiko Arase, MIT): https://registry.npmjs.org/qrcode-generator/-/qrcode-generator-1.4.4.tgz, `package/qrcode.js`, retrieved September 27, 2026; tarball integrity `sha512-HM7yY8O2ilqhmULxGMpcHSF1EhJJ9yBj8gvDEuZ6M+KGJ0YY2hKpnXvRD+hZPLrDVck3ExIGhmPtSdcjC+guuw==`. Draws the authenticator-app enrollment QR code in the browser, so the secret it encodes is never sent to a third-party image service.
