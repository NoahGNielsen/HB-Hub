// Minesweeper's game: builds the board (a table) for the difficulty picked in the bar at the bottom, and handles
// clicks: a click reveals a cell, Shift + click or a right click flags it (a long press does that on most phones). Hit
// a mine and every mine shows; open every cell without one and you win. The state is just the globals below (board is
// rows of Cells, from cell.js).
// Copied from Nick Arocho's Minesweeper, https://github.com/nickarocho/minesweeper, for the frame on games/minesweeper/
// (frame.php). Changed for HB Hub:
// - the pictures are linked from /assets/minesweeper/images/, and the Read Me/Source Code links open safely in a new tab
// - a click on a difficulty button's picture (not just its text) picks it too, instead of breaking the board
// - a right click flags a cell too, a flag can be taken off again when the mine counter is at 0, and a click doesn't
//   open a flagged cell
// - for the leaderboard, the mines are on HB Hub's server (assets/php/games/minesweeper/minesweeper.php), not here:
//   every cell clicked open is sent to /games/minesweeper/move (the first one starts the game there and places the
//   mines, never on or next to it), which answers with the cells that opened and their numbers, and the whole board
//   once the game is over. Clicks are sent one after the other (queue), so fast clicking works. Flags stay here. A win
//   the server's anticheat finds faster than a person can click ends as result 4: won here, but not on the leaderboard.
//   The page around the frame (assets/js/minesweeper.js) hears how it went from the minesweeper:* events below.
//   Where they're sent is the <body>'s data-api (frame.php): /play/minesweeper/move for the free game without an
//   account, whose "game" is a sealed token with the board in it instead of an id, but is used just the same here.
// - anticheat: the whole file is in a function, so its state and functions (openCell(), board, ...) can't be reached
//   from the console, and a click on the board made by code (el.click(), dispatchEvent(), an auto clicker browser
//   extension: anything whose event isn't isTrusted; an auto clicker program outside the browser makes real clicks) stops the game and tells the server, which ends it as scripted (caught()).
(function() {
/*----- constants -----*/
var bombImage = '<img src="/assets/minesweeper/images/bomb.png" alt="Mine">';
var flagImage = '<img src="/assets/minesweeper/images/flag.png" alt="Flag">';
var wrongBombImage = '<img src="/assets/minesweeper/images/wrong-bomb.png" alt="Wrong flag">'
var sizeLookup = {
  '9': {totalBombs: 10, tableWidth: '245px'},
  '16': {totalBombs: 40, tableWidth: '420px'},
  '30': {totalBombs: 160, tableWidth: '794px'}
};
var api = document.body.dataset.api || '/games/minesweeper/move'; // HB Hub: where the cells go (frame.php)
var colors = [
  '',
  '#0000FA',
  '#4B802D',
  '#DB1300',
  '#202081',
  '#690400',
  '#457A7A',
  '#1B1B1B',
  '#7A7A7A',
];

/*----- app's state (variables) -----*/
var size = 16;
var board;
var bombCount;
var timeElapsed;
var adjBombs;
var hitBomb;
var elapsedTime;
var timerId;
var winner;
var gameId; // HB Hub: the server's id for the game, null until the first answer
var queue; // HB Hub: the clicks waiting for the server, one after the other
var round = 0; // HB Hub: goes up with every new board, so an answer for the board before is ignored
var stopped; // HB Hub: the server said the game is over (another tab started one), so nothing more opens

/*----- cached element references -----*/
var boardEl = document.getElementById('board');

/*----- event listeners -----*/
document.getElementById('size-btns').addEventListener('click', function(e) {
  // HB Hub: the button's picture has no id, so look for the button around it (the original read e.target's own id)
  var button = e.target.closest('button');
  if (!button) return;
  size = parseInt(button.id.replace('size-', ''));
  init();
  render();
});

// HB Hub: one function for both clicks. flagging is true for Shift + click and a right click (the original only had
// Shift + click, in the click listener). A flag can only go on while the counter is above 0, but always comes off again,
// and Shift + click never opens the cell (in the original, it did when the counter was at 0).
function clickCell(e, flagging) {
  if (winner || hitBomb || stopped) return;
  var clickedEl;
  clickedEl = e.target.tagName.toLowerCase() === 'img' ? e.target.parentElement : e.target;
  if (clickedEl.classList.contains('game-cell')) {
    if (!e.isTrusted) { // HB Hub: made by code, not a person
      caught();
      return;
    }
    var row = parseInt(clickedEl.dataset.row);
    var col = parseInt(clickedEl.dataset.col);
    var cell = board[row][col];
    if (flagging) {
      if (!cell.revealed && (cell.flagged || bombCount > 0)) {
        bombCount += cell.flag() ? -1 : 1;
      }
    } else if (!cell.flagged && !cell.revealed) { // HB Hub: a flagged cell has to be unflagged before it can be opened
      if (!timerId) setTimer(); // HB Hub: the clock starts with the first cell opened, like the server's
      openCell(row, col, clickedEl);
    }
    render();
  }
}

// HB Hub: tells the page around the frame (assets/js/minesweeper.js) something, as a minesweeper:<name> event.
function tellPage(name, detail) {
  try {
    window.parent.document.dispatchEvent(new CustomEvent('minesweeper:' + name, { detail: detail }));
  } catch (e) {
    // the frame opened on its own, or the page around it isn't HB Hub's: nobody to tell
  }
}

// HB Hub: sends a cell to the server to open it, after the clicks before it have their answers (the first one gives
// the game's id). The answer's cells are opened here; a cell the server opened under a flag loses the flag.
function openCell(row, col, clickedEl) {
  var thisRound = round;
  queue = queue.then(function() {
    if (thisRound !== round || winner || hitBomb || stopped) return;
    var body = new URLSearchParams({ action: 'open', row: row, col: col });
    if (gameId) {
      body.set('game', gameId);
    } else {
      body.set('size', size);
    }
    return fetch(api, { method: 'POST', headers: { Accept: 'application/json' }, body: body })
      .then(function(response) {
        return response.json().then(function(data) {
          if (thisRound !== round) return;
          if (!response.ok) {
            clearInterval(timerId);
            stopped = data.error === 'gameOver'; // over on the server: nothing more can be opened
            tellPage('error', { error: data.error || 'failed' });
            return;
          }
          applyAnswer(data, clickedEl);
        });
      })
      .catch(function() {
        if (thisRound === round) tellPage('error', { error: 'offline' });
      });
  });
}

// HB Hub: the anticheat saw a click made by code. Nothing more opens (the clicks still waiting are skipped, as they
// check stopped), and once the clicks already sent have their answers (so the game's id is known), the server is told
// to end the game as scripted. Before the first answer there's no game on the server yet, so nothing to tell.
function caught() {
  stopped = true;
  clearInterval(timerId);
  var thisRound = round;
  queue = queue.then(function() {
    if (thisRound !== round || !gameId || winner || hitBomb) return;
    var body = new URLSearchParams({ action: 'scripted', game: gameId });
    return fetch(api, { method: 'POST', headers: { Accept: 'application/json' }, body: body })
      .catch(function() {}); // offline: the game ends as left once another one is started
  });
  tellPage('scripted', {});
}

// HB Hub: puts what the server answered on the board (see move.php for its shape).
function applyAnswer(data, clickedEl) {
  gameId = data.game;
  var over = data.result !== 0;
  var won = data.result === 1 || data.result === 4; // 4: won, but too fast for the leaderboard (the server's anticheat)
  data.cells.forEach(function(entry) {
    var cell = board[entry[0]][entry[1]];
    if (entry[2] === -1) {
      cell.bomb = true;
      if (won && !cell.flagged) { // won: every mine gets a flag, like in Windows
        cell.flagged = true;
        bombCount -= 1;
      }
    } else {
      cell.adjBombs = entry[2];
      if (cell.flagged && (!over || won)) { // a wrong flag stays for a loss, to show it was wrong
        cell.flagged = false;
        bombCount += 1;
      }
    }
    cell.revealed = true;
  });
  if (data.result === 2) {
    hitBomb = true;
    clearInterval(timerId);
    clickedEl.style.backgroundColor = 'red';
    tellPage('lost', {});
  } else if (won) {
    winner = true;
    clearInterval(timerId);
    elapsedTime = Math.floor(data.ms / 1000);
    document.getElementById('timer').innerText = elapsedTime.toString().padStart(3, '0');
    tellPage(data.result === 4 ? 'tooFast' : 'won', { size: size, ms: data.ms, best: data.best, isBest: data.isBest });
  }
  render();
}

boardEl.addEventListener('click', function(e) {
  clickCell(e, e.shiftKey);
});

boardEl.addEventListener('contextmenu', function(e) {
  if (!e.target.closest('.game-cell')) return;
  e.preventDefault(); // no browser menu on the board
  clickCell(e, true);
});

function createResetListener() { 
  document.getElementById('reset').addEventListener('click', function() {
    init();
    render();
  });
}

/*----- functions -----*/
function setTimer () {
  timerId = setInterval(function(){
    elapsedTime += 1;
    document.getElementById('timer').innerText = elapsedTime.toString().padStart(3, '0');
  }, 1000);
};

function buildTable() {
  var topRow = `
  <tr>
    <td class="menu" id="window-title-bar" colspan="${size}">
      <div id="window-title"><img src="/assets/minesweeper/images/mine-menu-icon.png" alt=""> Minesweeper</div>
      <div id="window-controls"><img src="/assets/minesweeper/images/window-controls.png" alt=""></div>
    </td>
  <tr>
    <td class="menu" id="folder-bar" colspan="${size}">
      <div id="folder1"><a href="https://github.com/nickarocho/minesweeper/blob/master/readme.md?utm_source=hbhub.noahgajnielsen.dk" target="_blank" rel="noopener noreferrer">Read Me </a></div>
      <div id="folder2"><a href="https://github.com/nickarocho/minesweeper?utm_source=hbhub.noahgajnielsen.dk" target="_blank" rel="noopener noreferrer">Source Code</a></div>
    </td>
  </tr>
  </tr>
    <tr>
      <td class="menu" colspan="${size}">
          <section id="status-bar">
            <div id="bomb-counter">000</div>
            <div id="reset"><img src="/assets/minesweeper/images/smiley-face.png" alt="New game"></div>
            <div id="timer">000</div>
          </section>
      </td>
    </tr>
    `;
  boardEl.innerHTML = topRow + `<tr>${'<td class="game-cell"></td>'.repeat(size)}</tr>`.repeat(size);
  boardEl.style.width = sizeLookup[size].tableWidth;
  createResetListener();
  var cells = Array.from(document.querySelectorAll('td:not(.menu)'));
  cells.forEach(function(cell, idx) {
    cell.setAttribute('data-row', Math.floor(idx / size));
    cell.setAttribute('data-col', idx % size);
  });
}

function buildArrays() {
  var arr = Array(size).fill(null);
  arr = arr.map(function() {
    return new Array(size).fill(null);
  });
  return arr;
};

// HB Hub: the cells start without mines or numbers: the server has them, and sends each cell's as it opens (the
// original placed the mines here, with addBombs(), and counted them with getBombCount()).
function buildCells(){
  board.forEach(function(rowArr, rowIdx) {
    rowArr.forEach(function(slot, colIdx) {
      board[rowIdx][colIdx] = new Cell(rowIdx, colIdx, board);
    });
  });
};

function init() {
  buildTable();
  board = buildArrays();
  buildCells();
  bombCount = sizeLookup[`${size}`].totalBombs;
  elapsedTime = 0;
  clearInterval(timerId);
  timerId = null;
  hitBomb = false;
  winner = false;
  stopped = false;
  gameId = null;
  queue = Promise.resolve();
  round += 1;
  tellPage('new', { size: size });
};

function render() {
  document.getElementById('bomb-counter').innerText = bombCount.toString().padStart(3, '0');
  var seconds = timeElapsed % 60;
  var tdList = Array.from(document.querySelectorAll('[data-row]'));
  tdList.forEach(function(td) {
    var rowIdx = parseInt(td.getAttribute('data-row'));
    var colIdx = parseInt(td.getAttribute('data-col'));
    var cell = board[rowIdx][colIdx];
    if (cell.flagged) {
      td.innerHTML = flagImage;
    } else if (cell.revealed) {
      if (cell.bomb) {
        td.innerHTML = bombImage;
      } else if (cell.adjBombs) {
        td.className = 'revealed'
        td.style.color = colors[cell.adjBombs];
        td.textContent = cell.adjBombs;
      } else {
        td.className = 'revealed'
      }
    } else {
      td.innerHTML = '';
    }
  });
  if (hitBomb) {
    document.getElementById('reset').innerHTML = '<img src="/assets/minesweeper/images/dead-face.png" alt="New game">';
    runCodeForAllCells(function(cell) {
      if (!cell.bomb && cell.flagged) {
        var td = document.querySelector(`[data-row="${cell.row}"][data-col="${cell.col}"]`);
        td.innerHTML = wrongBombImage;
      }
    });
  } else if (winner) {
    document.getElementById('reset').innerHTML = '<img src="/assets/minesweeper/images/cool-face.png" alt="New game">';
    clearInterval(timerId);
  }
};

function runCodeForAllCells(cb) {
  board.forEach(function(rowArr) {
    rowArr.forEach(function(cell) {
      cb(cell);
    });
  });
}

init();
render();
})();