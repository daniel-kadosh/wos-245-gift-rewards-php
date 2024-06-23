<?php

app()->get('/add', function () {
    #response()->json(['message' => 'Congrats!! You\'re on Leaf API']);
    response()->markup("<h1>WOS #245 Gift Rewards</h1>
        <ul><li>Database players: <a href=\"/players\">/players</a></li>
        <li>Send a reward: <a href=\"/send/\">/send/</a>[giftcode]</li>
        <li>Add a player: <a href=\"/add/\">/add</a>[playerID]</li>
        <li>Remove a player: <a href=\"/remove/\">/remove</a>[playerID]</li>
        </ul>");
});
