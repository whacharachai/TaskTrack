# TaskTrack

Project-based construction progress reporting with a per-project chat room. Vanilla HTML/JS/CSS, PHP JSON API, SQLite.

## Setup

```
D:\nginx\php\php.exe api\auth.php <username> <password> [admin|worker|user]   # creates data/tasktrack.db + the user
```

Schema is created and migrated on first request. Then open `http://localhost/TaskTrack/`.

Login is remembered for **2 days** (session cookie lifetime + `gc_maxlifetime`). After that the API answers 401 and the UI bounces to the login page.

## Roles

| role | can do |
| --- | --- |
| `admin` | everything: projects, users, tasks, progress, chat, files |
| `worker` | add and edit tasks, add/edit/delete progress amounts, chat, attach files |
| `user` | look only |

A plain user gets no write buttons in the UI and the API answers 403 for the same calls.

## Projects

Everything lives in a project: tasks, chat, files, timeline, calendar. An admin creates projects under the **Projects** tab and picks **which named users can see each one** — visibility is per user, not per role, and admins always see everything.

Tasks inherit the project's list, so there is no per-task access list; a task always belongs to exactly one project, and a sub-task must belong to the same project as its parent. On first start after the upgrade, existing role grants are converted into named user assignments once and the `projects.roles` column is dropped.

## Chat

One room per project. Any member can post a message and attach files or photos. The room polls every 5 seconds while the tab is visible, so it updates without a reload.

The last 60 messages are cached in `localStorage`, so re-opening a room paints immediately and the first poll only fetches what is new. History is paged: the room opens with the latest 30, and scrolling to the top (or the **load older messages** button) prepends the previous 30 while keeping your place. Messages and their files stay after a message is deleted; only admins or the author can delete a message.

## Files and photos

Uploads go through `api/upload.php` in 2 MB slices, up to **100 MB** per file, and are resumable:

1. `?action=start` — declares the name and total size, returns an `upload_id`
2. `?action=chunk&id=…` — raw body plus an `X-Chunk-Offset` header; the server replies with the byte offset it actually has and rejects a stale offset instead of appending out of order
3. `?action=finish&id=…` — moves the file into place, generates a thumbnail for JPEG/PNG/WebP

A client that reconnects or reopens the tab asks `?action=status` and continues from the confirmed offset rather than re-sending the file. Chunk bytes on disk are the source of truth, so a lost database write cannot corrupt a resumed upload. Names are stored as random hex, extensions are whitelisted, and the MIME type is read back with `fileinfo`.

Files are served only through `api/files.php?download=ID` or `?thumb=ID`, which check login and project access first.

## nginx

Added to the `server { }` block of `D:\nginx\conf\nginx.conf`, then `nginx -s reload`:

```nginx
client_max_body_size 16m;    # caps one 2 MB slice, not the whole file
client_body_timeout 300s;
fastcgi_read_timeout 600s;
fastcgi_send_timeout 600s;
location ~ ^/TaskTrack/(data|uploads)/ { deny all; }   # keep the .db and raw uploads out of the web root
```

Without `client_max_body_size` the 1 MB default rejects the second chunk of every upload.

## Who does what

- **admin** — everything, including the Projects and Users tabs.
- **worker** — creates and edits tasks, adds/edits/deletes daily progress amounts, chats and attaches files. Cannot touch projects, users, or delete tasks.
- **user** — read-only: lists, timeline, calendar, chat reading.

## Views

- **Tasks** — list with cumulative progress bars; open a task to report progress, edit a day's amount, and see its files.
- **Timeline** — bars per task across its date range, sub-tasks indented under the parent.
- **Calendar** — month grid, Sunday first. Each task contributes a `Task name start` row on its start date and a `Task name end` row on its end date, alongside the progress entries logged that day. Click any row to open the task.
- Progress entries are the amount done **on that day**, not a running total; a task's done amount is the sum of all its entries.

## Checks

```
D:\nginx\php\php.exe tests\rollup.php    # parent/child rollup maths: caps, zero totals, cycles
```