# ✨ Features

## 💬 Chat

| | |
|---|---|
| **Direct messages & group chats** | Message requests (with Block, to stop someone sending more), replies, emoji reactions, edits, deletions and unread counts |
| **Group roles** | Group owners and admins can rename the group, change its icon and background, and add, kick, mute and promote members |
| **Message groups** | Messages sent in a row by the same person (less than 10 minutes apart) are shown together, and pointing at one shows when it was sent |
| **Chat notices** | Changes in a group (new name, new admin, someone joined) show up in the chat |
| **Nicknames & profiles** | Give people nicknames, and open the profile of whoever you're DMing straight from the chat |
| **GIFs & smileys** | GIF picker powered by Giphy, plus a smiley menu |
| **Attachments** | Send files and pictures. Pictures are turned into JPEG XL in the browser (lossless, or visually lossless for JPEGs), other files are zipped on the server. Everyone has 10 tickets of 5 MB (admins can give or take away more), which come back when an attachment expires (90 days, or 3 for big ones) or is deleted |
| **Formatting** | Discord-style `**bold**`, `*italic*`, `__underline__`, `~~strikethrough~~`, `` `code` `` and code blocks, `#` headings, lists and `[masked](https://links)`, from a right-click menu on the message box or Ctrl+B / I / U |
| **@mentions** | Type `@` for a list of the people in the chat. Mentions light up, and the person mentioned gets a notification (not from muted chats or people they blocked). Works in Chess and Matador's game chats too |
| **Chat menu** | Right-click a chat to mute its notifications for a while, hide it or leave it |
| **Link safety** | Links ask before they open, and links to IP loggers and other sites on the URL blacklist (`src/assets/urlBlacklist.txt`) can't be sent at all, in chats or game chats |

## 🎰 Casino

Played with virtual chips only. No real money is involved anywhere.

| | |
|---|---|
| **Chip wallet** | Every account starts with 2,500 chips and can collect 2,500 more once a day. All casino games share the same chips |
| **Blackjack** | Play solo or at a table with up to 5 players. Tables can be public, joined with a 6-character code, or invite-only, and the host picks the minimum bet. Insurance, splits and doubling, with timers so nobody can stall the table |
| **Baccarat** | Punto banco at a table of 2-6 players, public, with a code or invite-only. Bet on Player (1:1), Banker (1:1 less 2.7%, so neither side has an edge), Tie (8:1) and the pairs (11:1). 8 decks, shuffled at 75% with a notice to everyone at the table |
| **Roulette** | European roulette (single zero). Every inside and outside bet can be placed straight on the board |
| **Leaderboards** | Separate leaderboards for Blackjack, Baccarat and Roulette that can be sorted by any column. Win % only counts once you've played 20 rounds |

## 🎲 Games

| | |
|---|---|
| **Matador** | The Danish Monopoly for 2-4 players. Games can be public, joined with a 6-character code, or invite-only |
| **House rules** | One dice (a 6 gives another roll), no auctions: a property nobody buys stays with the bank. Get out of jail by paying, using a "benådet" card or rolling a 6. Taxes and Prøv lykken payments go into Parkering's account, and whoever lands on Parkering gets it (a room setting, on by default) |
| **Full game** | Houses and hotels, mortgages, Prøv lykken cards, the income tax choice, and trading properties, money and cards with the other players at any time |
| **Turn timer** | 30 seconds to act, reset by every move. Run out and your turn is passed on. The timer pauses while you owe money, and the host can pause the whole game |
| **Saves** | The game saves after every move. Leave and your seat is kept for you. The host can give an empty seat to someone else, or save and stop the game to continue it later |
| **In-game chat** | A chat for each game, plus a sound when it's your turn |
| **Chess** | Two-player chess with every rule: castling, en passant, promotion, and draws by stalemate, repetition, the 50-move rule and too few pieces. Public, code or invite-only games, with or without a clock (3+2 up to 30 minutes) |
| **Chess extras** | Draw offers, resigning, aborting before both have moved, claiming the win when your opponent has gone, the host pausing the game (and the clocks), rematches with swapped colours, arrows and highlighted squares drawn with the right mouse button (like chess.com), an in-game chat and a leaderboard |
| **Wordle** | The daily word game: guess the five-letter word in six tries. There's an English and a Danish word every day (the Danish keyboard has æ, ø and å), the same for everyone, with stats, sharing and a leaderboard for each language with win %, average guesses and the current and longest streak |

## 🔔 Notifications

| | |
|---|---|
| **Notification bell** | Invites, message requests, @mentions, kicks and admin changes show up under the bell in the top bar |
| **Browser notifications** | Pop-ups for new messages, except from muted chats |

## 🔐 Account & security

| | |
|---|---|
| **Profiles** | Profile picture, name, year, class and bio |
| **Two-factor login** | TOTP codes from any authenticator app, set up with a QR code |
| **Login protection** | Password strength rules, a common-password list and lockout after failed attempts. Devices you've logged in on before have their own lock, so nobody can lock you out by guessing |
| **Logged in devices** | See every browser you're logged in on and when it was last used, log out any of them, or log out everywhere at once |
| **Self-blocking** | Block the Casino and Games pages during school hours (8 AM - 3 PM) or all the time. Turning a block off waits until school is over, or 6 hours for "All the time", so it can't be switched off in the moment |
| **Account deletion** | Delete your account and its data from the settings page |
| **Feedback** | The Feedback button at the bottom of the side bar sends bugs, ideas and wishes straight to the admins |

## 🛠️ For site admins

| | |
|---|---|
| **Admin panel** | Search every user or chat by name or id (or right-click one in the chat). Users: info, logins, messages and attachments, and ban, edit, reset or delete them, delete their attachments or give or take away attachment tickets. Chats: members and messages, and rename, remove the icon, change the background, kick members or delete it |
| **Ghost bans** | Ghost ban someone from their admin panel page: they aren't told and the chat works like normal for them, but their messages, files, edits, reactions and the chats they start or add people to only show to themselves. Nobody gets a notification or request from them. Admins still see it all, marked as only seen by the sender |
| **Banned pictures** | Banning someone bans their profile picture too: any account that uploads the same picture (or a resized or slightly cropped copy), or signs up with it, is banned straight away. Add, view and remove pictures under Banned pictures in the admin panel's side menu |
| **Feedback inbox** | Read what users send with the Feedback button, with who sent it and from which page, under Feedback in the admin panel's side menu. Mark it as done or delete it; the number of new ones shows on Admin in the side bar |
| **Admin log** | Everything admins do in the admin panel is logged, opening a user or chat included (as that shows their messages and personal info): who did what to whom, when, and why (every action asks for a reason in a popup first). Automatic bans for a banned profile picture show up there too. Under Admin log in the admin panel's side menu, or the Admin log button on a user or chat. Can't be changed or deleted, kept for a year |
| **Maintenance mode** | Turn off logins and show a message, straight from the [site config](config.md) |
| **Section switches** | Open the Casino and the Games section to everyone, only to admins, or to nobody |

---

[← Back to the docs](README.md)
