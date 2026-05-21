#!/usr/bin/perl
# Minimal Perl CGI script — CGI/1.1 output format.
use strict;
use warnings;

print "Content-Type: text/html\r\n\r\n";
print "<!DOCTYPE html>\n";
print "<html><head><title>Perl CGI</title></head><body>\n";
print "<h1>Hello from Perl CGI!</h1>\n";
print "<p>Script: " . ($ENV{SCRIPT_FILENAME} // "unknown") . "</p>\n";
print "<p>Method: " . ($ENV{REQUEST_METHOD} // "unknown") . "</p>\n";
print "</body></html>\n";
