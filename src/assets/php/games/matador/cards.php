<?php
// The Prøv lykken cards: one deck, shuffled when the game starts. A card that's been read goes to the bottom of the deck,
// except the two "benådet" cards, which the player keeps until they use them to get out of jail (or trade them).
// The texts are in Danish, as on the real cards. What each one does ('do', played by hbHubMatadorDrawCard() in game.php):
//   move (field)         move forward to the field, 4,000 kr for passing START
//   back (steps)         move back, without passing START
//   nearestFerry         move forward to the nearest ferry and pay its owner double rent (or buy it from the bank)
//   jail                 go straight to jail
//   jailCard             keep the card, to get out of jail with
//   pay (amount)         pay the bank
//   receive (amount)     the bank pays you
//   repairs (house, hotel)  pay for every house and hotel you own
//   fromEach (amount)    every other player pays you
//   legat                the Matador-legat, when you're worth at most 15,000 kr

const HBHUB_MATADOR_CARDS = [
    1 => ['text' => 'Ryk frem til START.', 'do' => ['move', 0]],
    2 => ['text' => 'Ryk frem til START.', 'do' => ['move', 0]],
    3 => ['text' => 'Ryk frem til Frederiksberg Allé. Hvis De passerer START, indkassér da 4.000 kr.', 'do' => ['move', 11]],
    4 => ['text' => 'Ryk frem til Grønningen. Hvis De passerer START, indkassér da 4.000 kr.', 'do' => ['move', 24]],
    5 => ['text' => 'Ryk frem til Strandvejen. Hvis De passerer START, indkassér da 4.000 kr.', 'do' => ['move', 19]],
    6 => ['text' => 'Tag ind på Rådhuspladsen.', 'do' => ['move', 39]],
    7 => ['text' => 'Tag med Mols-Linien. Flyt brikken frem, og hvis De passerer START, indkassér da 4.000 kr.', 'do' => ['move', 15]],
    8 => ['text' => 'Ryk tre felter tilbage.', 'do' => ['back', 3]],
    9 => ['text' => 'Ryk tre felter tilbage.', 'do' => ['back', 3]],
    10 => ['text' => 'Ryk brikken frem til det nærmeste rederi, og betal ejeren to gange den leje, han ellers er berettiget til. Hvis selskabet ikke ejes af nogen, kan De købe det af banken.', 'do' => ['nearestFerry']],
    11 => ['text' => 'Ryk brikken frem til det nærmeste rederi, og betal ejeren to gange den leje, han ellers er berettiget til. Hvis selskabet ikke ejes af nogen, kan De købe det af banken.', 'do' => ['nearestFerry']],
    12 => ['text' => 'Gå i fængsel. Ryk direkte til fængslet. Selv om De passerer START, indkasserer De ikke 4.000 kr.', 'do' => ['jail']],
    13 => ['text' => 'Gå i fængsel. Ryk direkte til fængslet. Selv om De passerer START, indkasserer De ikke 4.000 kr.', 'do' => ['jail']],
    14 => ['text' => 'I anledning af kongens fødselsdag benådes De herved for fængsel. Dette kort kan opbevares, indtil De får brug for det, eller De kan sælge det.', 'do' => ['jailCard']],
    15 => ['text' => 'I anledning af kongens fødselsdag benådes De herved for fængsel. Dette kort kan opbevares, indtil De får brug for det, eller De kan sælge det.', 'do' => ['jailCard']],
    16 => ['text' => 'Oliepriserne er steget, og De skal betale 500 kr. pr. hus og 2.000 kr. pr. hotel.', 'do' => ['repairs', 500, 2000]],
    17 => ['text' => 'Ejendomsskatterne er steget. Ekstraudgifterne er: 800 kr. pr. hus, 2.300 kr. pr. hotel.', 'do' => ['repairs', 800, 2300]],
    18 => ['text' => 'De har modtaget Deres tandlægeregning. Betal 2.000 kr.', 'do' => ['pay', 2000]],
    19 => ['text' => 'Betal for vognvask og smøring 300 kr.', 'do' => ['pay', 300]],
    20 => ['text' => 'Betal 200 kr. for levering af 2 kasser øl.', 'do' => ['pay', 200]],
    21 => ['text' => 'De har kørt frem for "fuldt stop". Betal 1.000 kr. i bøde.', 'do' => ['pay', 1000]],
    22 => ['text' => 'Betal 3.000 kr. for reparation af Deres vogn.', 'do' => ['pay', 3000]],
    23 => ['text' => 'Betal 3.000 kr. for reparation af Deres vogn.', 'do' => ['pay', 3000]],
    24 => ['text' => 'De har været en tur i udlandet og haft for mange cigaretter med hjem. Betal told 200 kr.', 'do' => ['pay', 200]],
    25 => ['text' => 'De har parkeret ulovligt. Betal 200 kr. i bøde.', 'do' => ['pay', 200]],
    26 => ['text' => 'Betal Deres bilforsikring: 1.000 kr.', 'do' => ['pay', 1000]],
    27 => ['text' => 'De har købt 4 nye dæk til Deres vogn. Betal 1.000 kr.', 'do' => ['pay', 1000]],
    28 => ['text' => 'Modtag udbytte af Deres aktier: 1.000 kr.', 'do' => ['receive', 1000]],
    29 => ['text' => 'Modtag udbytte af Deres aktier: 1.000 kr.', 'do' => ['receive', 1000]],
    30 => ['text' => 'Modtag udbytte af Deres aktier: 1.000 kr.', 'do' => ['receive', 1000]],
    31 => ['text' => 'Kommunen har eftergivet et kvartals skat. Hæv i banken 3.000 kr.', 'do' => ['receive', 3000]],
    32 => ['text' => 'De har vundet i Klasselotteriet. Modtag 500 kr.', 'do' => ['receive', 500]],
    33 => ['text' => 'De har vundet i Klasselotteriet. Modtag 500 kr.', 'do' => ['receive', 500]],
    34 => ['text' => 'Deres præmieobligation er udtrukket. De modtager 1.000 kr. af banken.', 'do' => ['receive', 1000]],
    35 => ['text' => 'Deres præmieobligation er udtrukket. De modtager 1.000 kr. af banken.', 'do' => ['receive', 1000]],
    36 => ['text' => 'Grundet dyrtiden har De fået gageforhøjelse. Modtag 1.000 kr.', 'do' => ['receive', 1000]],
    37 => ['text' => 'De har solgt nogle gamle møbler på auktion. Modtag 1.000 kr. af banken.', 'do' => ['receive', 1000]],
    38 => ['text' => 'Værdien af egen avl fra nyttehaven udgør 200 kr., som De modtager af banken.', 'do' => ['receive', 200]],
    39 => ['text' => 'De har en række med elleve rigtige i tipning. Modtag 1.000 kr.', 'do' => ['receive', 1000]],
    40 => ['text' => 'Det er Deres fødselsdag. Modtag af hver medspiller 200 kr.', 'do' => ['fromEach', 200]],
    41 => ['text' => 'De skal holde familiefest og får et tilskud fra hver medspiller på 500 kr.', 'do' => ['fromEach', 500]],
    42 => ['text' => 'De har lagt penge ud til et sammenskudsgilde. Mærkværdigvis betaler alle straks. Modtag fra hver medspiller 500 kr.', 'do' => ['fromEach', 500]],
    43 => ['text' => 'De modtager "Matador-legatet for værdigt trængende", stort 40.000 kr. Ved værdigt trængende forstås, at Deres formue, d.v.s. Deres kontante penge + skøder + bygninger, ikke overstiger 15.000 kr.', 'do' => ['legat']],
];
