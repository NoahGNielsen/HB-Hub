// Minesweeper's game: builds the board (a table) for the difficulty picked in the bar at the bottom, places the mines,
// and handles clicks: a click reveals a cell, Shift + click or a right click flags it (a long press does that on most
// phones). Hit a mine and every mine shows; open every cell without one and you win. The state is just the globals below
// (board is rows of Cells, from cell.js), nothing is saved or sent anywhere.
// Copied from Nick Arocho's Minesweeper, https://github.com/nickarocho/minesweeper, for the frame on games/minesweeper/
// (frame.php). Changed for HB Hub:
// - the pictures are linked from /assets/minesweeper/images/, and the Read Me/Source Code links open safely in a new tab
// - a click on a difficulty button's picture (not just its text) picks it too, instead of breaking the board
// - a right click flags a cell too, a flag can be taken off again when the mine counter is at 0, and a click doesn't
//   open a flagged cell
/*----- constants -----*/
var bombImage = '<img src="/assets/minesweeper/images/bomb.png" alt="Mine">';
var flagImage = '<img src="/assets/minesweeper/images/flag.png" alt="Flag">';
var wrongBombImage = '<img src="/assets/minesweeper/images/wrong-bomb.png" alt="Wrong flag">'
var sizeLookup = {
  '9': {totalBombs: 10, tableWidth: '245px'},
  '16': {totalBombs: 40, tableWidth: '420px'},
  '30': {totalBombs: 160, tableWidth: '794px'}
};
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
  if (winner || hitBomb) return;
  var clickedEl;
  clickedEl = e.target.tagName.toLowerCase() === 'img' ? e.target.parentElement : e.target;
  if (clickedEl.classList.contains('game-cell')) {
    if (!timerId) setTimer();
    var row = parseInt(clickedEl.dataset.row);
    var col = parseInt(clickedEl.dataset.col);
    var cell = board[row][col];
    if (flagging) {
      if (!cell.revealed && (cell.flagged || bombCount > 0)) {
        bombCount += cell.flag() ? -1 : 1;
      }
    } else if (!cell.flagged) { // HB Hub: a flagged cell has to be unflagged before it can be opened
      hitBomb = cell.reveal();
      if (hitBomb) {
        revealAll();
        clearInterval(timerId);
        clickedEl.style.backgroundColor = 'red';
      }
    }
    winner = getWinner();
    render();
  }
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

function revealAll() {
  board.forEach(function(rowArr) {
    rowArr.forEach(function(cell) {
      cell.reveal();
    });
  });
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
      <div id="folder1"><a href="https://github.com/nickarocho/minesweeper/blob/master/readme.md" target="_blank" rel="noopener noreferrer">Read Me </a></div>
      <div id="folder2"><a href="https://github.com/nickarocho/minesweeper" target="_blank" rel="noopener noreferrer">Source Code</a></div>
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

function buildCells(){
  board.forEach(function(rowArr, rowIdx) {
    rowArr.forEach(function(slot, colIdx) {
      board[rowIdx][colIdx] = new Cell(rowIdx, colIdx, board);
    });
  });
  addBombs();
  runCodeForAllCells(function(cell){
    cell.calcAdjBombs();
  });
};

function init() {
  buildTable();
  board = buildArrays();
  buildCells();
  bombCount = getBombCount();
  elapsedTime = 0;
  clearInterval(timerId);
  timerId = null;
  hitBomb = false;
  winner = false;
};

function getBombCount() {
  var count = 0;
  board.forEach(function(row){
    count += row.filter(function(cell) {
      return cell.bomb;
    }).length
  });
  return count;
};

function addBombs() {
  var currentTotalBombs = sizeLookup[`${size}`].totalBombs;
  while (currentTotalBombs !== 0) {
    var row = Math.floor(Math.random() * size);
    var col = Math.floor(Math.random() * size);
    var currentCell = board[row][col]
    if (!currentCell.bomb){
      currentCell.bomb = true
      currentTotalBombs -= 1
    }
  }
};

function getWinner() {
  for (var row = 0; row<board.length; row++) {
    for (var col = 0; col<board[0].length; col++) {
      var cell = board[row][col];
      if (!cell.revealed && !cell.bomb) return false;
    }
  } 
  return true;
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