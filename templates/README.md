# Email templates

`send-mail.php` sends each RSVP and wish in the template the site asks for. A site sends `"template": "<folder name>"` (this project sets it with `VITE_MAIL_TEMPLATE` in `.env`), and the script uses that folder here:

```
templates/
├── default/          ← used when a site names no template, or one that doesn't exist
├── khaled-shorouk/
│   ├── email.html    ← the HTML email
│   ├── email.txt     ← the plain-text version (optional)
│   └── template.json ← names, date, venue, timezone, badge colours
└── mahmoud-maryam/
```

If a folder has no `email.html` or `email.txt`, the script uses `default`'s. Folder names may use lowercase letters, digits, `-` and `_`.

## Adding an invitation

1. Copy a folder, e.g. `khaled-shorouk` → `ahmed-sara`.
2. Change `template.json`: the names, date and venue.
3. Change `email.html` if you want another look: colours, layout, wording.
4. Upload the folder to the mail server, next to the others.
5. In that site's `.env`: `VITE_MAIL_TEMPLATE=ahmed-sara`, then rebuild the site.

## template.json

```json
{
  "groom_ar": "خالد", "bride_ar": "شروق",
  "groom": "Khaled",  "bride": "Shorouk",
  "date_ar": "الأحد ٦ ديسمبر ٢٠٢٦", "date_en": "Sunday 6 December 2026",
  "venue_ar": "قلعة جرين بلازا",   "venue_en": "Green Plaza Castle",
  "timezone": "Africa/Cairo",
  "badges": {
    "attending":     { "ink": "#F3E0B0", "bg": "#4D1422" },
    "not_attending": { "ink": "#4D1422", "bg": "#EFE4D6" },
    "wish":          { "ink": "#300A13", "bg": "#E9D3A0" }
  }
}
```

Every text value is usable in the templates as `{{its name}}`, and you can add your own (e.g. `"hashtag": "#KhaledAndShorouk"` → `{{hashtag}}`). `timezone` sets the "Received" time. `badges` colours the label at the top of each email.

## Placeholders

| Write | Gets |
|---|---|
| `{{name}}` | a value, made safe for HTML in `email.html` (a guest's `<b>` shows as text, not bold) |
| `{{#rows}} … {{/rows}}` | repeated for each row of details (name, attending, guests, received) |
| `{{#quote}} … {{/quote}}` | shown only when there is a wish message |
| `{{^first}} … {{/first}}` | shown only when `first` is false (e.g. a divider above every row but the first) |

Values the script adds:

| Value | Meaning |
|---|---|
| `badge_ar`, `badge_en` | `سيحضر إن شاء الله · Attending`, `لن يتمكن من الحضور · Not attending` or `تهنئة جديدة · New wishes` |
| `badge_ink`, `badge_bg` | that badge's colours, from `template.json` |
| `guest` | the guest's name |
| `quote` | the wish message (empty for an RSVP) |
| `rows` | the details. Inside `{{#rows}}`: `label_ar`, `label_en`, `value`, `first` |

`<!-- comments -->` in `email.html` are removed before sending, so notes can stay in the template.

## Tips for email HTML

Mail apps (Gmail, Outlook, Apple Mail) support far less than browsers do. Use tables for layout and `style="…"` on each element. Avoid `<style>` blocks, flex/grid, and web fonts you rely on. Start from an existing template and change colours and text: those always work.
