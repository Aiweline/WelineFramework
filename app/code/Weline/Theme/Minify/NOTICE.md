# Weline Theme Minify

CSS/JS minification used at static deploy time for Theme resource tags
(`theme:css` / `theme:js`) and other `pub/static` assets.

## JS

`Weline\Theme\Minify\Js\JsMin` is adapted from
[mrclay/jsmin-php](https://github.com/mrclay/jsmin-php) (MIT), itself a
modified port of Douglas Crockford's JSMin.

- Upstream license: see `LICENSE.MIT.jsmin`

## CSS

`Weline\Theme\Minify\Css\CssMin` is a conservative in-house minifier
(comment strip + whitespace collapse; preserves strings and `url(...)`).

This is not a Composer runtime dependency.
