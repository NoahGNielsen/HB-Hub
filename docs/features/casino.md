# 🎰 Casino

Played with virtual chips only. No real money is involved anywhere.

| | |
|---|---|
| **Casino home** | Your chips, your place on the casino leaderboard and the daily chips at the top, the 30-day calendar as a row of chips, a card for each game with its open tables, players right now and how you've done in it (your last spins for Roulette), and the top 5 players |
| **Chip wallet** | Every account starts with 2,500 chips. All casino games share the same chips |
| **Daily chips** | Free chips once a day on a 30-day calendar: day 1 is 2,500, and every day in a row moves one day further, mostly for more (with a few lean days, a bonus at the end of each week and 15,000 on day 30). Missing a day starts it over at day 1, and so does finishing day 30 |
| **Blackjack** | Play solo or at a table with up to 5 players. Tables can be public, joined with a 6-character code, or invite-only, and the host picks the minimum bet. Insurance, splits and doubling, with timers so nobody can stall the table |
| **Baccarat** | Punto banco, solo or at a table with up to 6 players. Tables can be public, joined with a code, or invite-only. Bet on Player (1:1), Banker (1:1 less 2.7%, so neither side has an edge), Tie (8:1) and the pairs (11:1). 8 decks, shuffled at 75% with a notice to everyone at the table |
| **Roulette** | European roulette (single zero), at one table the whole site shares. Every inside and outside bet can be placed straight on the board, and everyone sees everyone's bets (hover a player to see only theirs). The first bet starts a 20-second clock; the wheel spins when it runs out or when everyone with bets has pressed Ready, and one ball decides it for all. Chips are only taken when the wheel spins, and bets you no longer have the chips for are taken off. A list of players shows their bets, then what each won or lost; "Same again" puts your last bets back |
| **Leaderboards** | A casino leaderboard with the chips everyone has won or lost in each game and in all of them together, and their chips now. Plus separate leaderboards for Blackjack, Baccarat and Roulette with wins, losses and win %. All can be sorted by any column, and win % only counts once you've played 20 rounds |
| **Free tables** | Solo Blackjack, solo Baccarat and Roulette can be played without an account at `/play/blackjack`, `/play/baccarat` and `/play/roulette`, with 2,500 play chips that only live in the page (a new stack when they run out, and never carried over to an account). The table is a token encrypted by the server, so the shoe and hole card stay hidden, and it's played with the same rules and code as the members' solo tables. The free Roulette is a table of your own, spun whenever you press Spin. See [Games](games.md#free-games-without-an-account) for the sign-up prompt |

The Casino can be opened to everyone, only to admins, or to nobody with `casino_access` in the [config](../config.md),
and players can block it for themselves (see [Account & security](account.md)).

---

[← Back to the features](index.md)
