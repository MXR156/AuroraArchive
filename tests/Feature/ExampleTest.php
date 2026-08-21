<?php

test('guests are redirected to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});
