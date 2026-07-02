# Blog — usage notes

Place Markdown posts in `src/blog/` with filenames in the `YYYY-MM-DD.md` format (for example `2026-01-03.md`). The first H1 line is used as the post title. Example:

```markdown
# My post title

This is the post body. Links and images work as shown below.

[Visit example](https://example.com)

![Alt text](./images/photo.png)
```

Recommended image locations and path examples:
- Put images that belong to a post in `src/blog/images/` and reference them from the post as `./images/photo.png`.
- You may also reference site-wide images from `src/assets/images/` using `/src/assets/images/your.png` or use an absolute URL (`https://...`).

Notes about rendering in `src/blog.php`:
- Links written as `[text](url)` will open in a new tab with `rel="noopener noreferrer"` for safety.
- Image markup `![alt](url)` is converted to `<img>` and styled responsively; CSS class `img-fluid` and `.blog-post img` ensure images scale to the container.
- If you prefer different behavior for paths (for example, serving images from `/assets/`), use absolute paths in your Markdown.

Image width
- You can set an explicit width (in pixels) by appending `|<px>` to the image path. Example:

```markdown
![Alt text](./images/photo.png|200)
```

This will render an image with `width:200px` (height auto) while still remaining responsive. Use smaller or larger values as needed.

Troubleshooting:
- If an image doesn't appear, check the resulting HTML page URL and verify the image path resolves correctly in the browser (relative paths are resolved against the blog page URL).
- If you want front-matter or additional Markdown features, consider using a dedicated Markdown library (e.g. `erusev/parsedown`) and I can integrate it.
