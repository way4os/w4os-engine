# Logos

The logos every bridge of the engine shows (the Grav plugin, w4os, the helpers): `W4OS\Engine\Assets\Logos` gives their files, the API serves them at `/logos/{name}`. The logo of a grid is not here: it is its own setting, `LogoURL` in the `[Helpers]` section of `Helpers.ini` (`Grid::logoUrl()`, `logo` in `/grids/{grid}/info` and `/grids/{grid}/addresses`).

- `w4os-logo-v1-long.png` (1244x312), `w4os-logo-v1-long-300x75.png`: the long logo of w4os, for a footer.
- `w4os-logo-v1-square.png` (960x960), `w4os-icon-v1.png` (256x256): the square logo and the icon of w4os.
- `cropped-w4os-logo-v1-square-32x32.png`, `-180x180.png`, `-192x192.png`: the favicon, the Apple touch icon, the Android icon.
- `opensimulator-logo.png` (453x273), `opensimulator-logo-24x14.png`: the logo of OpenSimulator, and its menu icon.

The w4os logos are the brand of the w4os project (Magiiic), from its corporate kit; other sizes are there.

The OpenSimulator logo is the one of the OpenSimulator project (opensimulator.org), credited to Adam Frisby. The project publishes no license for it: its BSD license covers the code, not the logo. It is shipped to name OpenSimulator and link to it, as the sites of the grids do; ask the project before any other use.
