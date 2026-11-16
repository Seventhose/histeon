# Media and fonts

Images and fonts weigh more than everything else on a page. Histeon works on the WordPress media library, adds collections to keep it tidy, compresses images before upload and writes by itself the attributes that make the right image load for each screen. Fonts, on the other hand, it serves directly from your site.

## The media manager

The **Media manager** is the window where you choose and upload images without leaving the builder. It opens in three ways:

- from the **Add** menu ([[Ctrl+I]]) → **Image from the library…**, to insert a new image;
- by selecting an image on the page and pressing **Choose image…** in the **Properties** panel, to replace it;
- from the **Media library** button in the left bar (or from the command palette), to browse the whole library: images, video, audio and PDF.

Opened from the bar, the library inserts nothing on the first click: a click on a file shows its details on the right. Type, dimensions, size and upload date; the address, with **Copy**; the alternative text and the caption, with **Save details**; the collections; and **Where it is used**, that is the contents that name it (at any size) or have it as featured image. Contents you cannot edit are only counted. **Insert in the page** puts it after the selected element; **Open in WordPress** opens its page in wp-admin.

The images are those of the WordPress media library: what you upload here you find again in **Media** in wp-admin, and vice versa. To search, use the **Search the library…** field.

### Collections

**Collections** are folders for images, nested too: "Events", "Events → Concerts", "Icons"…

- The left column shows **All images**, **No collection** and the tree of collections, each with its number of images.
- To create a collection, type the name in the **New collection…** field. If you have one open, the field becomes **Subcollection…** and the new collection is created inside it.
- A collection has no public pages: it only serves whoever builds the site.

To create collections and assign them, being able to upload files in WordPress is enough.

### Uploading images

1. Open the collection you want to put the images in.
2. Choose the **Compression before upload** (see the table).
3. Press **Upload** and choose up to 20 files: each one has its own progress bar in the **Uploads** list.

| Compression | What it does |
|---|---|
| **Web (max 2560 px, AVIF or WebP)** | Reduces the longest side to 2560 px and converts to AVIF or WebP, keeping the lighter one. Good for almost everything. |
| **Light (max 1600 px, AVIF or WebP)** | Like Web, with the longest side at 1600 px: for small images on the page or very lightweight sites. |
| **Original** | The file goes up as it is. |

Compression happens in the browser, before sending: the server receives the already lightweight file. The orientation of photos taken with a phone is respected. JPEG, PNG and WebP are compressed. GIF, animated PNG and WebP, and SVG go up as they are, and so do files over 32 MB or 40 megapixels. A PNG never becomes AVIF: it stays lossless, or becomes a lossy WebP if that weighs less. The builder keeps the lightest format, and only if it weighs less than the original; otherwise the original is uploaded. A photo takes a few seconds. The encoders are Histeon Publish's, served by your own site: no file goes to outside services.

> WordPress's limits always apply: allowed file types, maximum upload size and permission to upload files.

### Your own presets and images already uploaded

**Presets…**, next to the compression menu, adds the site's presets: a name, the longest side, the formats and the quality. The last preset used stays chosen.

An image already in the library is made lighter from its details: **Compress with “preset”** resizes and recompresses it in the same format, where it is. Its address does not change, the sizes WordPress made keep their names, and the original is set aside: **Restore the original** puts it back. When there is nothing to gain, the image stays as it was.

### Alt text and details

The **⋯** button on an image opens the **Image details**:

- **Alt text**: the description for screen reader users and for search engines. **Save alt text** saves it on the image in the library, so it applies wherever you use it later.
- The **Collections** the image belongs to.
- **Use this image** to insert it into the page.

### Video, audio and PDF

From the **Add** menu → **Video, audio or PDF from the library…** the same manager opens with the **File type** filter. A video or an audio goes into the page with its playback controls; a PDF goes in as a link to the file.

### Videos from YouTube or Vimeo, loaded on click

From the **Add** menu → **YouTube or Vimeo video…** you paste the video's address. Histeon downloads the preview image once into the site's library and inserts a box with the preview and the play button. Visitors do not contact YouTube or Vimeo until they start the video: only then does the player load (from `youtube-nocookie.com`, or from Vimeo with “do not track”). The canvas shows the preview; try the player in the **Preview**.

### Maps loaded on click

From the **Add** menu → **Map (OpenStreetMap or Google)…** you paste an OpenStreetMap or Google Maps link (or the “embed” code they give) and type the place's name. The page shows a box with the name and “Load the map”: the map arrives, and the provider receives a request, only when a visitor opens it. Without JavaScript the link opens the map on the provider's site.

### All the alternative texts of the page

From the palette (**Alternative texts of the page**) or from **Checks**, a table shows every image of the page with its alternative text: write it right there, mark as **Decorative** the images that say nothing, and with **Only without alternative text** you see just the ones to fix.

### Focal point

When the box crops the image, in **Properties** click **Focal point** on the spot to keep in view (a face, a product); the arrows move it and **Centre** removes it.

> A decorative image can have empty alt text; an image that says something (a product, a person, a chart) must describe it. The checks before publishing flag images without alt text.

## Responsive images

A phone screen doesn't need a 2560 px photo. WordPress creates several sizes of each uploaded image; Histeon makes sure the browser picks the right one.

### Images chosen from the library

When you insert an image from the media manager, Histeon writes in the element:

- `src`, the original image;
- `srcset`, the other sizes with the same proportions (cropped thumbnails are excluded, because they would distort the image);
- `sizes`, the hint for the browser about how much space it takes up;
- `width` and `height`, so the browser reserves the space and the page doesn't "jump" while loading;
- `alt`, the alt text saved in the library.

They are normal attributes: you see and edit them in the **Properties** panel or in the HTML panel. If you change the layout, for example by putting the image in a narrow column, you can correct `sizes` by hand.

### Images that come from content

An image written with an expression, as in a post loop, gets the same attributes at visit time:

```html
<img src={item.image.url} alt={item.image.alt}>
```

Histeon knows which library image it is, so it adds `width`, `height`, `srcset` and `sizes`. It adds only what is missing: what you wrote yourself is not touched.

- For a specific WordPress size, write its name: `{item.image.medium_large}` uses that size, with its dimensions.
- If the image has `loading="lazy"`, `sizes` starts with `auto`: the browser uses the real width of the image on the page and downloads the most suitable file.
- `loading`, `decoding` and `fetchpriority` are handled by WordPress, as for the rest of the site.

The image keys you can read are `url`, `alt`, `width`, `height`, `caption`, `title`, `srcset`, `sizes` and `id`.

## Local fonts

With Histeon **Local fonts**, the font files live on your site and are served by the site itself: pages don't request them from other servers, Google's included.

You find them in two places: in the builder, in **Design system** → **Site fonts**, and in **Histeon → Settings**, **Local fonts** section. Everyone who builds sees them; only administrators add or remove them.

### From the Design system

**Site fonts** shows the families served by the site, each with a sample written in its typeface and the weights it has. **Copy** copies the font stack (`"Inter", sans-serif`); with Histeon CSS the **Text**, **Headings** and **Code** buttons put it in the generator's typefaces, previewed in the canvas, and **Save in development** keeps it.

To add a family, type part of its name or its kind (`serif`, `monospace`…) in **Add from Google Fonts**: the list ships with Histeon, so searching doesn't ask Google anything. Choose the family, tick the weights (and **Italic too**, when there is one) and press **Download to the site**. If Google does not have one of the chosen weights, Histeon downloads the others. A family that is not in the list is added from Settings, by typing its name.

### Importing from Google Fonts

1. In **Import from Google Fonts** enter the **Family**, for example `Inter`.
2. In **Weights** enter the weights you need, separated by commas: `400, 700`.
3. Check **italic too** if you need it.
4. Press **Download to the site**.

The server downloads the files from Google only once and from then on serves them from your site. Visitors to the pages never contact Google.

### Uploading a file

If you have your own font or one purchased with a web license:

1. In **Upload a file** choose the file, in **WOFF2** or **WOFF** format, at most 2 MB.
2. Specify **Family**, **Weight** and **Style** (**normal** or **italic**).
3. Press **Upload**. Repeat for each weight and style.

Histeon recognizes the file by its content, not by its name: a file that isn't really a font is rejected.

### Using the fonts

In CSS, you use them with the family name:

```css
body {
  font-family: "Inter", system-ui, sans-serif;
}
```

The font rules reach the site's pages, the block editor and the builder canvas automatically, with text displayed immediately while the font loads. If you export the static site, the font files are copied together with the pages.

To remove a font, press **Remove** next to its family in the list.

> Fonts are files and have no versions: adding or removing them takes effect immediately, without publishing a release. Before removing a font, check that the site's CSS no longer uses it.
