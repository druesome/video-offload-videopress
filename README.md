# Video Offload for VideoPress

A WordPress plugin that offloads locally-stored videos to VideoPress via Jetpack, then replaces local video references in post content with the native VideoPress block.

## Requirements

- WordPress 6.0+
- [Jetpack](https://wordpress.org/plugins/jetpack/) connected to WordPress.com
- A WordPress.com plan that includes VideoPress (Premium, Business, or Commerce)
- VideoPress feature active under Jetpack → Performance

## Installation

1. Download the zip and install via **Plugins → Add New → Upload Plugin**
2. Activate the plugin
3. Go to **Media → VideoPress Offload**

## Usage

### Offloading videos

- **Single video**: Click **Offload to VideoPress** next to any video in the Media Library or in the VideoPress Offload admin page
- **Bulk**: Use the **Offload All to VideoPress** button on the admin page to process all pending videos sequentially with a progress counter

### After offloading

Each offloaded video gets three actions:

- **View on VideoPress** — opens the video on VideoPress
- **Replace in Content** — finds every post/page that embeds the local video and replaces it with a `wp:videopress/video` block
- **Delete Local File** — permanently deletes the local file from the server (confirm VideoPress has the video first)

## How it works

The plugin calls Jetpack's internal `/videopress/v1/upload/{id}` REST endpoint, which handles chunked uploads and authentication with WordPress.com. Upload state is tracked in post meta so a page refresh mid-upload picks up where it left off and auto-polls until complete.

## Notes

- Offloading requires the site to be publicly accessible (VideoPress fetches the file from the server)
- Large files upload in chunks; the spinner stays visible for the full duration
- Clicking an individual Offload button disables the others until the upload completes or errors, preventing simultaneous uploads within the same page session
