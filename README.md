# Playlist Browser (codingtest5)

This repository contains a lightweight PHP web application that reads album data from TSV files located in `playlist/`, renders a searchable playlist view, and allows you to create new albums directly from the UI.

## Getting Started

1. **Dependencies**
   - PHP 8.1 or higher (the built-in web server is enough; no database is required).

2. **Install dependencies**
   - There are no Composer dependencies; the project relies only on core PHP.

3. **Run the development server**
   ```bash
   php -S localhost:8000 -t public
   ```
   Then open http://localhost:8000 in your browser.

## Usage

- The home screen lists every track from the `.tsv` albums under `playlist/`.
- Use the filters:
  - `Album` and `Artist` fields accept partial matches.
  - `Title prefix` filters tracks whose titles start with the provided string.
  - `Sort` lets you switch between the default (album + track order) and ascending duration.
- Add a new album by entering its name and providing track rows in the format `Title<TAB>Artist<TAB>Duration` (one line per track). The application saves the album as a new TSV file inside `playlist/`.

## Project Structure

```
playlist/             # Album TSV files live here
public/index.php      # Single page web UI and request handling
src/                  # Minimal domain classes and repository
```

## Sample Data

Two example albums (`Acoustic Moments.tsv` and `Spectrum Dreams.tsv`) are included so the list page can be explored immediately.

## Requirements Checklist

- ✅ Add new albums through the application (creates TSV files).
- ✅ Display a combined list of all tracks.
- ✅ Filter by album name, artist name, or title prefix (e.g., titles starting with “A”).
- ✅ Default ordering by album name + track number; optional sorting by duration.

## Next Steps

- Enhance validation (e.g., duration format checks).
- Add automated tests using PHPUnit.
- Containerize or deploy to a lightweight hosting target (see deployment notes below).

## Deployment Notes

To keep costs low while experimenting with Amazon Bedrock AgentCore, consider:
- Hosting the static PHP site on AWS Amplify or AWS Lightsail (cheapest option for small traffic).
- Using a minimal EC2 or Lightsail instance with Amazon Linux + PHP, fronted by Nginx/Apache.
- Integrating with Bedrock AgentCore via HTTPS in future iterations (not required for the current MVP).

Document IAM policies, networking, and cost monitoring before going live. A detailed deployment guide can be added once infrastructure decisions are finalized.
