# Images

The pictures the engine gives for what has none; `OpenSim\Assets\WebAssets` serves them, and the API at
`/grids/{grid}/assets/{id}.{format}` (see `logos/` for the logos).

- `assets-no-img.png` (256x256): the picture of nothing, for a place, a pick, a classified, a region. Its id is
  `201ce950-aa38-46d8-a8f1-4396e9d6be00` (`WebAssets::PLACEHOLDER`, the `W4OS_NOTFOUND_IMG` of w4os), and it stands for any
  missing picture by default. From w4os (Magiiic).
- `assets-no-person.png` (256x256): the silhouette of a person, for a profile picture that is not there, in-world or of the real
  life, and for an avatar model without a picture. Its id is `c14a1985-c549-4485-8779-7103d74ef5e0` (`WebAssets::PERSON`); a
  missing asset asked with `?default=person` gives it too. Drawn for the engine, not taken from a viewer: `assets-no-person.svg` is
  its source, under the license of the engine (AGPL-3.0-or-later). Made with ImageMagick:

      magick -background none share/images/assets-no-person.svg -strip -colorspace Gray -depth 8 share/images/assets-no-person.png
