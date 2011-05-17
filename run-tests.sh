#!/bin/bash

# Create c version (without buggy serial code)
make edid-decode-noserial

for i in data/*
do
    # We grep -v garbage due to uninitialized conformant_extension variable in parse_extension()
    #  behaving unpredictably
	if diff -ac2 <(./edid-decode-noserial $i | grep -v garbage ) <(php php-edid-decode.php $i | grep -v garbage ) #| grep -v "[^[:print:]]"
	#if cmp -s <(./edid-decode-noserial $i) <(php php-edid-decode.php $i)
	then
		echo "$i: passed";
	else
		echo "$i: failed (exiting)";
#		exit;
	fi
done
