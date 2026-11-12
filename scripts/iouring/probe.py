"""Exit 0 if the kernel lets this process create an io_uring ring, else 1.

Containers on Docker's default seccomp profile (moby 25+) get EPERM here,
so a passing io_uring job is only meaningful when this probe passes first.
"""
import ctypes
import os
import sys

libc = ctypes.CDLL(None, use_errno=True)
params = ctypes.create_string_buffer(120)  # struct io_uring_params
fd = libc.syscall(425, 8, params)          # __NR_io_uring_setup (x86_64)
if fd < 0:
    err = ctypes.get_errno()
    print(f"io_uring_setup refused: errno {err} ({os.strerror(err)})")
    sys.exit(1)
os.close(fd)
print("io_uring_setup OK")
