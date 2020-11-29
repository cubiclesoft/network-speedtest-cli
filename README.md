Cloud/Network Speed Tester
==========================

This is a powerful network speed tester but it is more than that.  It can utilize isolated, temporary Digital Ocean droplets in a real datacenter as the target for the tests rather than websites like speedtest.net that ISPs can easily include in their dastardly designs.

The tool boils results down into a simple JSON summary that is pretty easy to understand and can also be integrated into other tools (e.g. a script that generates an automated daily email report).

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/) [![Discord](https://img.shields.io/discord/777282089980526602?label=chat&logo=discord)](https://cubiclesoft.com/product-support/github/)

Features
--------

* SSH/SFTP (port 22) speed testing.
* Common TCP ports 80, 443, and 8080 as well as random TCP port speed testing using a custom TCP/IP server that supports speeds up to 2.2 Gbps down and 780 Mbps up.
* Basic network latency testing.  That is, how long it takes for packets to go round-trip between hosts.
* Automatic Digital Ocean droplet instance spin up and speed testing of SSH/SFTP and various TCP ports.  The Droplets this tool uses are around $0.007 USD per hour of usage but you can find $10 coupon codes online.
* Speedtest.net/OoklaServer speed testing.  Produces similar results to the single connection tests at speedtest.net.
* Also comes with a complete, question/answer enabled command-line interface making it easy to run.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your project.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

You need to have a reasonable version of PHP CLI installed to use this tool.  Windows binaries are available from [windows.php.net](https://windows.php.net/download/) (you probably want a x64 Thread Safe variant).  Mac OSX comes with PHP CLI pre-installed.  And all major Linux package managers make it easy to install (e.g. `sudo apt install php-cli` on Ubuntu).

Once you get PHP up and running, download and extract (or git clone) and then run the tool like this:

```
php speedtest.php
```

It'll guide you through the process of selecting and running a speed test.  Make sure no devices are doing anything on the network during speed testing and that the connection is over Ethernet not Wi-Fi.

Before doing speed testing via Digital Ocean, you will need to [set up an account on Digital Ocean](https://digitalocean.com/).  Then run:

```
php do.php
```

And follow the prompts to configure the Digital Ocean CLI tool.  After that, the main speed testing application will work as expected.  Each Digital Ocean droplet has a Gigabit fiber connection to the Internet (typically 1.5Gbps or more) that's way bigger than the average home Internet connection and your computer is the only device connecting to the droplet during the speed test, which eliminates a lot of spurious host-related issues.  Don't forget to remove droplets when you are done with them since they aren't free.

The tool can also be run with all of the answers to the questions passed in on the command-line:

```
php speedtest.php -?
php speedtest.php 20 5 ookla -hosttype closest -download 10 -upload 10
php speedtest.php 20 5 digitalocean -prefix speedtest -region nyc1 -keep Y
php speedtest.php 20 5 digitalocean -prefix speedtest -region sfo2 -keep N
php speedtest.php 20 5 ookla -hosttype id -id 11613 -download 10 -upload 10
php speedtest.php 20 5 ssh -profile webserver-speedtest
```

FAQ
---

Not getting your ISP's advertised speeds?  What next?

First off, realize that these tests and this tool only test single connection performance.  Single connection + latency tests are much more important than multi-connect tests when moving files around over TCP/IP.  Multi-connect tests are more important for general network usage.  I primarily move large files over SSH, so single connection tests matter more to me than multi-connect - although both are important to me.

Next, be sure to run the `ookla` test in the test suite.  It should produce similar results to the single connection test on [single.speedtest.net](https://single.speedtest.net/).  That should help provide some peace of mind.

One thing to note is that network speed tests vary based on a lot of factors.  The more "hops" between you and the host, the slower the connection will generally be.  TCP/IP also has overhead and TCP configurations have lots of settings because people keep monkeying with it (receive window size, congestion protocols, etc).  Physical distance to the host matters too.  The farther away the host is physically, the longer it takes for acknowledgment (ACK) packets to get back to the sender - that is, single-direction latency.

Calling your ISP's customer service department can sometimes fix some things too.  They like to push profiles down to the router that split the main data pipe into two or more segments.  They can push a profile down that makes it one big data pipe if that is what you prefer.  You can spot this behavior if speed testing shows that the download pipe is cut exactly in half (or thirds) for the single connection tests but saturates the link to advertised rates on multi-connect tests.  There are reasons to split the pipe but there are also reasons to not split the pipe.  Mostly it saves them money because few people will notice/care as they already have busy networks but the difference is noticeable on large file downloads and uploads, including video playback and upload.

This doesn't mean there won't be other problems.  There could be unexpected latency in their network or the destination network.  Packet loss somewhere in the middle could be happening.  Or your router could be doing traffic shaping.  Or your ISP could be traffic shaping.  Or a major DDoS attack underway somewhere along the route.  Or maybe packets are being split along multiple routes with some arriving sooner than others.  Or China might decide to route all Internet traffic through their country (it's happened a few times).  Well, the list goes on.

More Tools
----------

My Traceroute (MTR) is a pretty neat network latency tool that combines ping and traceroute into a single solution:

https://github.com/traviscross/mtr

I thought about automating it with:

```
mtr -o "J M X LSR NA B W V" -wzbc 100 [ipaddress]
```

However, there wasn't a good way to get command-line MTR running on Windows so I gave up on that option.

I also ran into a couple of command line (CLI) speedtest.net solutions.  They tended to do the multi-connect thing and weren't written in PHP.  I'm mostly interested in single connection speed tests (i.e. how fast each individual TCP/IP connection can go).  It's kind of pointless if you have to establish 4+ connections to a host to saturate the link.  Also, an all-in-one tool in a single language also helps eliminate "well, maybe it's the tool/language" issues.

There are some tools out there that can modify the TCP stack configuration.  I'm not going to list any of the ones I ran into here since I get nervous about software that magically modifies core OS settings and may leave the system in a worse state than it started with.  I'd rather see new protocols be designed for handling specific scenarios (e.g. moving a large file, moving thousands of tiny files at once) and leave the default TCP/IP settings as-is.
