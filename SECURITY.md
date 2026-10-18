# Security Policy

HB Hub is run by one student in their spare time. If you've found a security problem, thank you for taking the time to
report it. Please report it privately so it can be fixed before anyone else finds it.

## Supported versions

Only the live site, [hbhub.noahgajnielsen.dk](https://hbhub.noahgajnielsen.dk), and the latest commit on `main` are
supported. Every push to `main` is deployed straight away, so there are no older releases to patch.

## Reporting a vulnerability

**Please don't open a public issue, pull request or discussion for security problems.**

Report it in one of these ways:

1. **GitHub (preferred):** [open a private security advisory](https://github.com/NoahGNielsen/HB-Hub/security/advisories/new).
2. **Email:** [admin@noahgajnielsen.dk](mailto:admin@noahgajnielsen.dk). If you want to encrypt it, the PGP key is at
   [noahgajnielsen.dk/assets/publickey.txt](https://noahgajnielsen.dk/assets/publickey.txt).

English and Danish are both fine. Please include:

- what the problem is and what someone could do with it
- the page, endpoint or file it's in
- steps to reproduce it (a proof of concept helps a lot)
- your name or handle if you'd like to be credited

## What happens next

- I'll reply to say I've got your report, normally within a week.
- I'll keep you updated while I look into it and work on a fix.
- When it's fixed, I'll let you know. If you want, you'll be credited in the advisory.

Please give me a reasonable amount of time to fix it before you talk about it publicly.

## Scope

**In scope**

- The live site at `hbhub.noahgajnielsen.dk`
- The code in this repository

Things I especially want to hear about: getting into other people's accounts or chats, reading messages you shouldn't
be able to, getting around login protection or two-factor login, getting admin rights, SQL injection, XSS, CSRF, and
seeing hidden game information (like the order of the cards or the dealer's hole card).

Bugs that give you free chips or let you cheat in the games aren't always security problems, but please report those
too (privately, the same way) instead of using them. The chips are virtual and have no real value, but using a bug like
that on purpose counts as cheating under the [Terms](https://hbhub.noahgajnielsen.dk/legal/terms).

**Out of scope**

- Denial of service, load testing or anything else that slows the site down for others
- Social engineering, phishing or physical attacks against users, admins or the school
- The hosting provider's own systems
- Reports from automated scanners without a way to actually exploit the problem
- Missing best-practice headers or settings with no real impact
- Problems that need an already compromised device or browser

## Testing rules

When testing, please:

- only use accounts you own, and never access, change or delete other users' data
- stop and report as soon as you've confirmed a problem, and don't go further into the system than you need to
- not run anything that could slow down or break the site for others
- keep anything you find to yourself until it's fixed

If you stick to these rules and act in good faith, I won't take action against you for your research.

There's no bug bounty, since HB Hub is a free school project, but every report is really appreciated.
