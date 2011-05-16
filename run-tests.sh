#!/bin/bash

# Create c version
make

for i in data/*
do
	if diff -ac2 <(./edid-decode $i) <(php php-edid-decode.php $i)
	then
		echo "$i: passed";
	else
		echo "$i: failed (exiting)";
		exit;
	fi
done
