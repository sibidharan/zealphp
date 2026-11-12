// Create and destroy a 4096-entry ring (OpenSwoole's reactor size) in a loop
// and print liburing's real return value: OpenSwoole logs errno instead,
// which liburing doesn't set. Optional argv[1]: microseconds to sleep between.
#include <liburing.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

int main(int argc, char **argv) {
    unsigned gap = argc > 1 ? (unsigned) atoi(argv[1]) : 0;
    for (int i = 1; i <= 10; i++) {
        struct io_uring ring;
        struct io_uring_params p;
        memset(&p, 0, sizeof(p));
        int ret = io_uring_queue_init_params(4096, &ring, &p);
        printf("init %d (gap %uus): %s\n", i, gap, ret < 0 ? strerror(-ret) : "ok");
        if (ret == 0) io_uring_queue_exit(&ring);
        if (gap) usleep(gap);
    }
    return 0;
}
