# art

The images of the README: the logo in a light and a dark variant, a screenshot of the default
theme's home page in its light and dark mode, and the social preview. This directory is
`export-ignore`d, so none of it ships in the Composer package.

The README points at these files by absolute URL on `main`
(`https://raw.githubusercontent.com/astrophp/verse/main/art/<file>`), so they also render on
Packagist. A test (`tests/Integration/Docs/ArtTest.php`) checks that every file the README names is
here, that every image has alt text, that the screenshot has a light and a dark file, that nothing
here is unused, and that the screenshots are 2880 x 1800 pixels (1440 x 900 at 2x) and the social
preview 1280 x 640.

| File | What it shows |
| -- | -- |
| `logo-light.svg`, `logo-dark.svg` | The logo, for a light and a dark page |
| `home-light.png`, `home-dark.png` | The home page of a demo website in the default theme |
| `social-preview.png` | The repository's social preview, 1280 x 640 |

When the theme changes visibly, retake both screenshots of the same page at 1440 x 900 with a 2x
device scale, one with the light and one with the dark colour scheme, and look at them before you
commit: the page only, no scrollbar, no focus ring, and nothing personal in view.

`social-preview.png` is not referenced from the README. Upload it in the repository's settings,
under Social preview.
