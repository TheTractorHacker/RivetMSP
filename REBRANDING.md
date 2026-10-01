# RivetMSP

ITFlow MSP Edition is now RivetMSP, the MSP counterpart to RivetIT. It uses its own approved blue rivet-and-connected-plates logo, with a simplified SVG companion mark for navigation and browser tabs. Company names, uploaded logos and custom favicons retain precedence.

The product identity is defined in `includes/branding.php`. Legacy default application names resolve to RivetMSP at runtime; custom application names remain unchanged. Existing configuration files are not rewritten.

ITFlow upstream credit and the GPL license are retained. Repository URLs, update remotes, database identifiers, PHP namespaces, environment variables, API routes, webhook headers, mailbox folder names and backup paths remain compatible. This change does not rename the GitHub repository or external mobile applications.

## Logo assets

- `img/branding/rivetmsp-logo.png`: approved transparent wordmark, unchanged from the original generated artwork.
- `img/branding/logo-mark.svg`: scalable compact companion mark.
- `img/branding/favicon.svg` and `/favicon.ico`: matching browser icons.

Asset URLs are defined in `includes/branding.php`. The wordmark is displayed on a white surface for contrast in both light and dark themes. Login, setup, admin navigation, agent navigation and the client portal use the new identity. Uploaded company logos and favicons retain precedence.

Company-logo precedence is mandatory: an uploaded MSP logo replaces the product artwork, including login, admin navigation, setup and mobile app chrome. `GET /api/v1/branding` exposes only the company name and a same-origin logo path already public on the login screen. RivetMSP artwork is the no-company-logo fallback.
