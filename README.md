# Playlist Browser (codingtest5)

This repository contains a lightweight PHP web application that reads album data from TSV files stored under `playlist/<playlist-folder>/`, renders a searchable playlist view, and allows you to create new albums directly from the UI.

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

### Pointing to an External Playlist Folder

By default the app looks for playlist folders inside `playlist/` in this repository. You can either set `PLAYLIST_ROOT` before launching the server *or* paste any folder path directly into the “Playlist Folder” box on the home screen—Windows paths such as `C:\Users\you\Downloads\codingtest\playlist` are translated automatically when running inside WSL.

## Usage

- Pick the playlist folder you want to explore from the selector at the top of the track list.
- Change the root directory at any time via the “Playlist Folder” form; the app will reload the albums found in that folder (subdirectories become selectable playlists and standalone `.tsv` files are grouped under the root).
- The home screen lists every track from the `.tsv` albums inside the selected playlist folder.
- Use the filters:
  - `Album` and `Artist` fields accept partial matches.
  - `Title prefix` filters tracks whose titles start with the provided string.
- `Sort` lets you switch between the default (album + track order) and ascending duration.
- Add a new album by entering its name and providing track rows in the format `Title<TAB>Artist<TAB>Duration` (one line per track). The application saves the album as a new TSV file inside the currently selected playlist folder.

## Project Structure

```
playlist/                 # Root directory that holds playlist folders
playlist/<playlist>/      # Folder for one playlist; contains album TSV files
public/index.php          # Single page web UI and request handling
src/                      # Minimal domain classes and repository
```

Each playlist folder can house any number of albums (TSV files), and each album file contains tracks in `Title<TAB>Artist<TAB>Duration` order. The track index page aggregates all albums within the currently selected playlist.

## Sample Data

An example playlist lives under `playlist/demo/` with albums such as `Acoustic Moments.tsv` and `Spectrum Dreams.tsv` so the list page can be explored immediately after launching the server. Drop additional playlist folders next to `demo/` (e.g., copy the provided Windows `playlist` directory) and they will appear in the selector automatically.

## Requirements Checklist

- ✅ Switch between playlist folders and list the tracks inside each one.
- ✅ Add new albums through the application (creates TSV files).
- ✅ Display a combined list of all tracks.
- ✅ Filter by album name, artist name, or title prefix (e.g., titles starting with “A”).
- ✅ Default ordering by album name + track number; optional sorting by duration.

## Next Steps

- Enhance validation (e.g., duration format checks).
- Add automated tests using PHPUnit.
- Containerize or deploy to a lightweight hosting target (see deployment notes below).

## Deployment Notes

- Host the static PHP site on AWS Amplify, AWS Lightsail, or your preferred shared hosting.
- For small experiments, the built-in `php -S` server is often enough; switch to Apache/Nginx when you need TLS or process supervision.
