// pa-jail.cc -- Peteramati program sets up a jail for student code
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

#include <sys/types.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <sys/mount.h>
#include <sys/select.h>
#include <sys/time.h>
#include <unistd.h>
#include <cstdio>
#include <cstdlib>
#include <cstdarg>
#include <cstring>
#include <cerrno>
#include <csignal>
#include <poll.h>
#include <dirent.h>
#include <termios.h>
#include <pwd.h>
#include <grp.h>
#include <fcntl.h>
#include <utime.h>
#include <cassert>
#include <getopt.h>
#include <fnmatch.h>
#include <string>
#include <unordered_map>
#include <vector>
#include <iostream>
#include <sys/ioctl.h>
#include <sys/file.h>
#if __linux__
#include <mntent.h>
#include <sched.h>
#include <sys/signalfd.h>
#include <sys/sysmacros.h>
#elif __APPLE__
#include <sys/param.h>
#include <sys/ucred.h>
#include <sys/mount.h>
#endif

#define ROOT 0

#define FLAG_CP       1        // copy even if source is symlink
#define FLAG_BIND     2
#define FLAG_BIND_RO  4
#define FLAG_MOUNT    8

#ifndef O_PATH
#define O_PATH 0
#endif

typedef std::pair<dev_t, ino_t> devino;
namespace std { template <> struct hash<devino> {
    std::size_t operator()(const devino& di) const {
        return di.second | (di.first << (sizeof(std::size_t) - 8));
    }
}; }

static uid_t caller_owner;
static gid_t caller_group;

static std::unordered_map<std::string, int> dirtable;
static std::unordered_map<std::string, int> dst_table;
static std::unordered_map<devino, std::string> devino_table;
static int exit_value = 0;
static bool verbose = false;
static bool dryrun = false;
static bool quiet = false;
static bool doforce = false;
static bool no_onlcr = false;
static FILE* verbosefile = stdout;
static int timingfd = -1;
static std::string linkdir;
static std::string dstroot;
static std::string pidfilename;
static std::string pidcontents;
static std::string timingfilename;
static int pidfd = -1;
static volatile sig_atomic_t got_sigterm = 0;
#if __linux__
static int sigfd = -1;
#else
static int sigpipe[2];
#endif

enum jailaction {
    do_start, do_add, do_run, do_rm, do_mv
};


// error helpers

static int perror_fail(const char* format, const char* arg1) {
    fprintf(stderr, format, arg1, strerror(errno));
    exit_value = 1;
    return 1;
}

static __attribute__((noreturn))
void die(const char* fmt, ...) {
    va_list val;
    va_start(val, fmt);
    vfprintf(stderr, fmt, val);
    va_end(val);
    exit(1);
}

static __attribute__((noreturn))
void perror_die(const char* message) {
    die("%s: %s\n", message, strerror(errno));
}

static inline __attribute__((noreturn))
void perror_die(const std::string& message) {
    perror_die(message.c_str());
}


// pathname helpers

static std::string path_endslash(const std::string& path) {
    if (path.empty() || path.back() != '/') {
        return path + "/";
    } else {
        return path;
    }
}

static std::string path_noendslash(std::string path) {
    while (path.length() > 1 && path.back() == '/') {
        path = path.substr(0, path.length() - 1);
    }
    return path;
}

// returns a non-empty path that ends in slash
static std::string path_parentdir(const std::string& path) {
    size_t npos = path.length();
    while (npos > 1 && path[npos - 1] == '/') {
        --npos;
    }
    while (npos > 1 && path[npos - 1] != '/') {
        --npos;
    }
    return path.substr(0, npos);
}

static std::string shell_quote(const std::string& argument) {
    std::string quoted;
    size_t last = 0;
    for (size_t pos = 0; pos != argument.length(); ++pos) {
        if ((pos == 0 && argument[pos] == '~')
            || !(isalnum((unsigned char) argument[pos])
                 || argument[pos] == '_'
                 || argument[pos] == '-'
                 || argument[pos] == '~'
                 || argument[pos] == '.'
                 || argument[pos] == '/')) {
            if (quoted.empty()) {
                quoted = "'";
            }
            if (argument[pos] == '\'') {
                quoted += argument.substr(last, pos - last) + "'\\''";
                last = pos + 1;
            }
        }
    }
    if (quoted.empty()) {
        return argument;
    } else {
        quoted += argument.substr(last) + "'";
        return quoted;
    }
}


static const char* uid_to_name(uid_t u) {
    static uid_t old_uid = -1;
    static char buf[128];
    if (u != old_uid) {
        old_uid = u;
        if (struct passwd *pw = getpwuid(u)) {
            snprintf(buf, sizeof(buf), "%s", pw->pw_name);
        } else {
            snprintf(buf, sizeof(buf), "%u", (unsigned) u);
        }
    }
    return buf;
}

static const char* gid_to_name(gid_t g) {
    static gid_t old_gid = -1;
    static char buf[128];
    if (g != old_gid) {
        old_gid = g;
        if (struct group *gr = getgrgid(g)) {
            snprintf(buf, sizeof(buf), "%s", gr->gr_name);
        } else {
            snprintf(buf, sizeof(buf), "%u", (unsigned) g);
        }
    }
    return buf;
}


static int v_fchmod(int fd, mode_t mode, const std::string& pathname) {
    if (verbose) {
        fprintf(verbosefile, "chmod 0%o %s\n", mode, pathname.c_str());
    }
    return dryrun ? 0 : fchmod(fd, mode);
}

static int x_lchown(const char* path, uid_t owner, gid_t group) {
    if (verbose) {
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path);
    }
    if (!dryrun && lchown(path, owner, group) != 0) {
        return perror_fail("chown %s: %s\n", path);
    }
    return 0;
}

static int x_lchownat(int fd, const char* component, uid_t owner, gid_t group, const std::string& dirpath) {
    if (verbose) {
        fprintf(verbosefile, "chown -h %s:%s %s%s\n", uid_to_name(owner), gid_to_name(group), dirpath.c_str(), component);
    }
    if (!dryrun && fchownat(fd, component, owner, group, AT_SYMLINK_NOFOLLOW) != 0) {
        return perror_fail("chown %s: %s\n", (dirpath + component).c_str());
    }
    return 0;
}

static int x_fchown(int fd, uid_t owner, gid_t group, const std::string& path) {
    if (verbose) {
        fprintf(verbosefile, "chown -h %s:%s %s\n", uid_to_name(owner), gid_to_name(group), path.c_str());
    }
    if (!dryrun && fchown(fd, owner, group) != 0) {
        return perror_fail("chown %s: %s\n", path.c_str());
    }
    return 0;
}

static int v_mkdir(const char* pathname, mode_t mode) {
    if (verbose) {
        fprintf(verbosefile, "mkdir -m 0%o %s\n", mode, pathname);
    }
    return dryrun ? 0 : mkdir(pathname, mode);
}

static int v_mkdirat(int dirfd, const char* component, mode_t mode, const std::string& pathname) {
    if (verbose) {
        fprintf(verbosefile, "mkdir -m 0%o %s\n", mode, pathname.c_str());
    }
    return dryrun ? 0 : mkdirat(dirfd, component, mode);
}

static int v_ensuredir(std::string pathname, mode_t mode, bool nolink) {
    pathname = path_noendslash(pathname);
    auto it = dirtable.find(pathname);
    if (it != dirtable.end())
        return it->second;
    struct stat st;
    int r = (nolink ? lstat : stat)(pathname.c_str(), &st);
    if (r == 0 && !S_ISDIR(st.st_mode)) {
        errno = ENOTDIR;
        r = -1;
    }
    if (r == -1 && errno == ENOENT) {
        std::string parent_pathname = path_parentdir(pathname);
        if ((parent_pathname.length() == pathname.length()
             || v_ensuredir(parent_pathname, mode, false) >= 0)
            && v_mkdir(pathname.c_str(), mode) == 0)
            r = 1;
    }
    dirtable.insert(std::make_pair(pathname, r == 1 ? 0 : r));
    return r;
}

static int x_link(const char* oldpath, const char* newpath) {
    if (verbose)
        fprintf(verbosefile, "rm -f %s\nln %s %s\n", newpath, oldpath, newpath);
    if (!dryrun) {
        if (unlink(newpath) == -1 && errno != ENOENT)
            return perror_fail("rm %s: %s\n", newpath);
        if (link(oldpath, newpath) != 0)
            return perror_fail("ln %s: %s\n", (std::string(oldpath) + " " + std::string(newpath)).c_str());
    }
    return 0;
}

static int x_chmod(const char* path, mode_t mode) {
    if (verbose)
        fprintf(verbosefile, "chmod 0%o %s\n", mode, path);
    if (!dryrun && chmod(path, mode) != 0)
        return perror_fail("chmod %s: %s\n", path);
    return 0;
}

static bool x_mknod_eexist_ok(const char* path, mode_t mode, dev_t dev) {
    struct stat st;
    int old_errno = errno;
    bool ok = stat(path, &st) == 0 && st.st_mode == mode && st.st_rdev == dev;
    errno = old_errno;
    return ok;
}

static const char* dev_name(mode_t m, dev_t d) {
    static char buf[128];
    if (S_ISCHR(m))
        snprintf(buf, sizeof(buf), "c %d %d", major(d), minor(d));
    else if (S_ISBLK(m))
        snprintf(buf, sizeof(buf), "b %d %d", major(d), minor(d));
    else if (S_ISFIFO(m))
        return "p";
    else
        snprintf(buf, sizeof(buf), "%u %u", (unsigned) m, (unsigned) d);
    return buf;
}

static int x_mknod(const char* path, mode_t mode, dev_t dev) {
    if (verbose)
        fprintf(verbosefile, "mknod -m 0%o %s %s\n", mode, path, dev_name(mode, dev));
    if (!dryrun && mknod(path, mode, dev) != 0
        && (errno != EEXIST || !x_mknod_eexist_ok(path, mode, dev)))
        return perror_fail("mknod %s: %s\n", path);
    return 0;
}

static bool x_symlink_eexist_ok(const char* oldpath, const char* newpath) {
    char lnkbuf[4096];
    int old_errno = errno;
    ssize_t r = readlink(newpath, lnkbuf, sizeof(lnkbuf));
    bool answer = (size_t) r == (size_t) strlen(oldpath) && memcmp(lnkbuf, oldpath, r) == 0;
    errno = old_errno;
    return answer;
}

static int x_symlink(const char* oldpath, const char* newpath) {
    if (verbose)
        fprintf(verbosefile, "ln -s %s %s\n", oldpath, newpath);
    if (!dryrun
        && symlink(oldpath, newpath) != 0
        && (errno != EEXIST || !x_symlink_eexist_ok(oldpath, newpath)))
        return perror_fail("symlink %s: %s\n", (std::string(oldpath) + " " + newpath).c_str());
    return 0;
}

static int x_copy_utimes(const char* path, const struct stat& st) {
#if __linux__
    if (verbose)
        fprintf(verbosefile, "touch -m -d @%ld %s\n", st.st_mtime, path);
    if (!dryrun) {
        struct timespec ts[2];
        ts[0].tv_nsec = UTIME_OMIT;
        ts[1] = st.st_mtim;
        if (utimensat(-1, path, ts, AT_SYMLINK_NOFOLLOW) != 0)
            return perror_fail("utimensat %s: %s\n", path);
    }
#endif
    return 0;
}

static std::pair<pid_t, int> x_waitpid(pid_t child, int flags) {
    int status;
    while (1) {
        pid_t w = waitpid(child, &status, flags);
        if (w > 0 && WIFEXITED(status))
            return std::make_pair(w, WEXITSTATUS(status));
        else if (w > 0)
            return std::make_pair(w, 128 + WTERMSIG(status));
        else if (w == 0) {
            errno = EAGAIN;
            return std::make_pair((pid_t) -1, -1);
        } else if (w == -1 && errno != EINTR)
            return std::make_pair((pid_t) -1, -1);
    }
}


// jailmaking

#if __linux__
#define MFLAG(x) MS_ ## x
#elif __APPLE__
#define MFLAG(x) MNT_ ## x
#endif

struct mountarg {
    const char* name;
    int value;
    bool unparse;
};
static const mountarg mountargs[] = {
#if __linux__
    { "bind", MS_BIND, false },
    { "noatime", MS_NOATIME, true },
#endif
    { "nodev", MFLAG(NODEV), true },
#if __linux__
    { "nodiratime", MS_NODIRATIME, true },
#endif
    { "noexec", MFLAG(NOEXEC), true },
    { "nosuid", MFLAG(NOSUID), true },
#if __linux__
    { "private", MS_PRIVATE, true },
    { "rec", MS_REC, false },
#endif
#if __linux__ && defined(MS_RELATIME)
    { "relatime", MS_RELATIME, true },
#endif
#if __linux__
    { "remount", MS_REMOUNT, true },
#endif
    { "ro", MFLAG(RDONLY), true },
    { "rw", 0, true },
#if __linux__
    { "slave", MS_SLAVE, true },
#endif
#if __linux__ && defined(MS_STRICTATIME)
    { "strictatime", MS_STRICTATIME, true },
#endif
#if __linux__ && defined(MS_UNBINDABLE)
    { "unbindable", MS_UNBINDABLE, true },
#endif
};
static const mountarg* find_mountarg(const char* name, int namelen) {
    const mountarg* ma = mountargs;
    const mountarg* maend = ma + sizeof(mountargs) / sizeof(mountargs[0]);
    for (; ma != maend; ++ma)
        if ((int) strlen(ma->name) == namelen
            && memcmp(ma->name, name, namelen) == 0)
            return ma;
    return 0;
}


struct mountslot {
    std::string fsname;
    std::string type;
    unsigned long opts;
    std::string data;
    bool wanted;
    mountslot() : opts(0), wanted(false) {}
    mountslot(const char* fsname, const char* type, const char* mountopts);
    std::string debug_mountopts_args(unsigned long opts) const;
    std::string debug_mount_command(std::string dst, unsigned long opts) const;
    void add_mountopt(const char* mopt);
    const char* mount_data() const;
    bool mountable(std::string src, std::string dst) const;
    int x_mount(std::string dst, unsigned long opts);
};

mountslot::mountslot(const char* fsname_, const char* type_, const char* mopt)
    : fsname(fsname_), type(type_), opts(0), wanted(false) {
    while (mopt && *mopt) {
        const char* ok_first = mopt + strspn(mopt, ",");
        const char* ok_last = ok_first + strcspn(ok_first, ",=");
        const char* ov_last = ok_last + strcspn(ok_last, ",");
        if (const mountarg* ma = find_mountarg(ok_first, ok_last - ok_first)) {
            opts |= ma->value;
        } else if (ok_first != ov_last) {
            data += (data.empty() ? "" : ",") + std::string(ok_first, ov_last);
        }
        mopt = ov_last;
    }
}

std::string mountslot::debug_mountopts_args(unsigned long opts) const {
    std::string arg;
    if (!(opts & MFLAG(RDONLY))) {
        arg = "rw";
    }
    const mountarg* ma = mountargs;
    const mountarg* ma_last = ma + sizeof(mountargs) / sizeof(mountargs[0]);
    for (; ma != ma_last; ++ma) {
        if (ma->value && (opts & ma->value) && ma->unparse)
            arg += (arg.empty() ? "" : ",") + std::string(ma->name);
    }
    if (!data.empty()) {
        arg += (arg.empty() ? "" : ",") + data;
    }
#ifdef MS_BIND
    std::string start = opts & MS_REC ? " --rbind " : " --bind ";
    if ((opts & MS_BIND) && arg == "rw") {
        return start;
    } else if (opts & MS_BIND) {
        return start + "-o " + arg;
    }
#endif
    if (!arg.empty()) {
        return " -o " + arg;
    } else {
        return arg;
    }
}

std::string mountslot::debug_mount_command(std::string dst, unsigned long opts) const {
    return "mount -i -n -t " + type + debug_mountopts_args(opts) + " " + fsname + " " + dst;
}

void mountslot::add_mountopt(const char* inopt) {
    int inopt_len = strcspn(inopt, ",=");
    if (const mountarg* ma = find_mountarg(inopt, inopt_len)) {
        if (ma->value) {
            opts |= ma->value;
        } else {
            opts &= ~MFLAG(RDONLY);
        }
    } else {
        const char* mopt = data.c_str();
        while (*mopt) {
            const char* ok_first = mopt + strspn(mopt, ",");
            const char* ok_last = ok_first + strcspn(ok_first, ",=");
            const char* ov_last = ok_last + strcspn(ok_last, ",");
            if (ok_last - ok_first == inopt_len
                && memcmp(inopt, ok_first, inopt_len) == 0) {
                int offset = ok_first - data.data();
                data = std::string(data.data(), mopt)
                    + std::string(ov_last, data.data() + data.length());
                mopt = data.c_str() + offset;
            } else {
                mopt = ov_last;
            }
        }
        data += (data.empty() ? "" : ",") + std::string(inopt);
    }
}

const char* mountslot::mount_data() const {
    return data.empty() ? NULL : data.c_str();
}

static int mount_status = 0; // 0: add, 1: run pre-fork, 2: in child
static std::vector<std::string> delayed_mounts;

bool mountslot::mountable(std::string src, std::string dst) const {
    if (verbose && false) {
        fprintf(verbosefile, "-checkmount %s %s type=%s status=%d wanted=%d-\n",
                src.c_str(), dst.c_str(), type.c_str(), mount_status, wanted ? 1 : 0);
    }
    if ((src == "/proc" && type == "proc")
        || (src == "/dev/pts" && type == "devpts")) {
        return mount_status == 2;
    } else if (src == "/tmp" && type == "tmpfs") {
        return mount_status != 1;
    } else if (src == "/run" && type == "tmpfs") {
        return false;
    } else if ((src == "/sys" && type == "sysfs")
               || (src == "/dev" && type == "udev")
               || wanted) {
        if (mount_status == 1) {
            delayed_mounts.push_back(src);
            delayed_mounts.push_back(dst);
            return false;
        } else {
            return true;
        }
    } else {
        return false;
    }
}

int mountslot::x_mount(std::string dst, unsigned long opts) {
    if (verbose) {
        fprintf(verbosefile, "%s\n", debug_mount_command(dst, opts).c_str());
    }
    if (dryrun) {
        return 0;
    }
    return mount(fsname.c_str(), dst.c_str(), type.c_str(), opts, mount_data());
}


typedef std::unordered_map<std::string, mountslot> mount_table_type;
static mount_table_type mount_table;

static int populate_mount_table() {
    static bool mount_table_populated = false;
    if (mount_table_populated) {
        return 0;
    }
    mount_table_populated = true;
#if __linux__
    FILE* f = setmntent("/proc/mounts", "r");
    if (!f)
        return perror_fail("open %s: %s\n", "/proc/mounts");
    while (struct mntent* me = getmntent(f)) {
        mountslot ms(me->mnt_fsname, me->mnt_type, me->mnt_opts);
        mount_table[me->mnt_dir] = ms;
    }
    fclose(f);
    return 0;
#elif __APPLE__
    struct statfs* mntbuf;
    int nmntbuf = getmntinfo(&mntbuf, MNT_NOWAIT);
    for (struct statfs* me = mntbuf; me != mntbuf + nmntbuf; ++me) {
        mountslot ms(me->f_mntfromname, me->f_fstypename, "");
        ms.opts = me->f_flags;
        mount_table[me->f_mntonname] = ms;
    }
    return 0;
#endif
}

#if __APPLE__
int mount(const char*, const char* target, const char* fstype,
          unsigned long flags, const void*) {
    return ::mount(fstype, target, flags, NULL);
}

int umount(const char* dir) {
    return ::unmount(dir, 0);
}
#endif

static int handle_mount(std::string src, std::string dst, bool in_child) {
    auto it = mount_table.find(src);
    if (it == mount_table.end()
        || !it->second.mountable(src, dst)) {
        return 0;
    }

    auto dit = mount_table.find(dst);
    if (dit != mount_table.end()
        && dit->second.fsname == it->second.fsname
        && dit->second.type == it->second.type
        && dit->second.opts == it->second.opts
        && dit->second.data == it->second.data
        && !in_child) {
        // already mounted
        return 0;
    }

    auto xit = dst_table.find(dst);
    if (xit != dst_table.end()
        && xit->second > 1) {
        return 0;
    }
    dst_table[dst] = 2;

    if (in_child) {
        v_ensuredir(dst, 0555, true);
    }

    mountslot msx(it->second);
#if __linux__
    if (msx.type == "devpts" && in_child) {
        msx.add_mountopt("newinstance");
        msx.add_mountopt("ptmxmode=0666");
    }
    if ((msx.opts & MS_BIND) && in_child) {
        msx.add_mountopt("slave");
    }
#endif
    int r = msx.x_mount(dst, msx.opts);
    // if in child, try one more time with remount
    if (!dryrun && r != 0 && errno == EBUSY && in_child) {
        r = msx.x_mount(dst, msx.opts | MS_REMOUNT);
    }
#if __linux__
    // if bind mount, need to remount as slave
    if (r == 0 && (msx.opts & MS_BIND)) {
        r = msx.x_mount(dst, msx.opts | MS_REMOUNT);
    }
#endif
    if (r != 0) {
        return perror_fail("%s: %s\n", msx.debug_mount_command(dst, msx.opts).c_str());
    }
    return 0;
}

static int handle_umount(const mount_table_type::iterator& it) {
    if (verbose) {
        fprintf(verbosefile, "umount -i -n %s\n", it->first.c_str());
    }
    if (!dryrun && umount(it->first.c_str()) != 0) {
        fprintf(stderr, "umount %s: %s\n", it->first.c_str(), strerror(errno));
        exit(1);
    }
    if (dryrun) {
        dst_table[it->first.c_str()] = 3;
    }
    return 0;
}


static int handle_copy(std::string src, std::string subdst,
                       int flags, dev_t jaildev);
static int construct_jail(dev_t jaildev, std::string& str, bool nomount);

static void handle_symlink_dst(std::string dst, std::string src,
                               std::string lnk, dev_t jaildev)
{
    std::string root = dstroot;
    if (!linkdir.empty() && dst.substr(0, dstroot.length()) != dstroot) {
        root = linkdir;
    }

    // expand `lnk` into `dst`
    if (lnk[0] == '/') {
        src = lnk;
        dst = root + lnk;
    } else {
        while (true) {
            if (src.length() == 1) {
            give_up:
                return;
            }
            size_t srcslash = src.rfind('/', src.length() - 2),
                dstslash = dst.rfind('/', dst.length() - 2);
            if (srcslash == std::string::npos || dstslash == std::string::npos
                || dstslash < root.length()) {
                goto give_up;
            }
            src = src.substr(0, srcslash + 1);
            dst = dst.substr(0, dstslash + 1);
            if (lnk.length() > 3 && lnk[0] == '.' && lnk[1] == '.'
                && lnk[2] == '/') {
                lnk = lnk.substr(3);
            } else {
                break;
            }
        }
        src += lnk;
        dst += lnk;
    }

    if (dst.substr(root.length(), 6) != "/proc/") {
        handle_copy(src, dst.substr(root.length()), 0, jaildev);
    }
}

static int x_rm_f(const std::string &dst) {
    if (verbose) {
        fprintf(verbosefile, "rm -f %s\n", dst.c_str());
    }
    if (dryrun) {
        return 0;
    }
    int r = unlink(dst.c_str());
    if (r == -1 && errno != ENOENT) {
        return perror_fail("rm %s: %s\n", dst.c_str());
    }
    return 0;
}

static int x_cp_p(const std::string& src, const std::string& dst) {
    if (x_rm_f(dst)) {
        return 1;
    }
    if (verbose) {
        fprintf(verbosefile, "cp -p %s %s\n", src.c_str(), dst.c_str());
    }
    if (dryrun) {
        return 0;
    }

    pid_t child = fork();
    if (child == 0) {
        const char* args[6] = {
            "/bin/cp", "-p", src.c_str(), dst.c_str(), NULL
        };
        execv("/bin/cp", (char**) args);
        exit(1);
    } else if (child < 0) {
        return perror_fail("%s: %s\n", "fork");
    }

    int status = x_waitpid(child, 0).second;
    if (status == 0) {
        return 0;
    } else if (status != -1) {
        return perror_fail("/bin/cp %s: Bad exit status\n", dst.c_str());
    } else {
        return perror_fail("/bin/cp %s: Did not exit\n", dst.c_str());
    }
}

static inline int stat_mtimes_same(const struct stat& st1, const struct stat& st2) {
#if __linux__
    return st1.st_mtim.tv_sec == st2.st_mtim.tv_sec && st1.st_mtim.tv_nsec == st2.st_mtim.tv_nsec;
#else
    return st1.st_mtime == st2.st_mtime;
#endif
}

static int do_copy(const std::string& dst, const std::string& src,
                   const struct stat& ss, bool reuse_link, dev_t jaildev) {
    struct stat ds;
    int r = lstat(dst.c_str(), &ds);
    if (r == 0
        && ss.st_mode == ds.st_mode
        && ss.st_uid == ds.st_uid
        && ss.st_gid == ds.st_gid
        && ((!S_ISREG(ss.st_mode) && !S_ISLNK(ss.st_mode))
            || ss.st_size == ds.st_size)
        && ((!S_ISBLK(ss.st_mode) && !S_ISCHR(ss.st_mode))
            || ss.st_rdev == ds.st_rdev)
        && ((!S_ISREG(ss.st_mode) && !S_ISLNK(ss.st_mode))
            || stat_mtimes_same(ss, ds))) {
        if (S_ISREG(ss.st_mode)) {
            auto di = std::make_pair(ss.st_dev, ss.st_ino);
            devino_table.insert(std::make_pair(di, dst));
        }
        return 0;
    }

    // check for hard link to already-created file
    if (S_ISREG(ss.st_mode)) {
        if (reuse_link) {
            auto di = std::make_pair(ss.st_dev, ss.st_ino);
            auto it = devino_table.find(di);
            if (it != devino_table.end())
                return x_link(it->second.c_str(), dst.c_str());
            devino_table.insert(std::make_pair(di, dst));
        }
        return x_cp_p(src, dst);
    } else if (S_ISDIR(ss.st_mode)) {
        mode_t perm = ss.st_mode & (S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (r == 0 && !S_ISDIR(ds.st_mode)) {
            errno = ENOTDIR;
            return perror_fail("%s: %s\n", dst.c_str());
        }
        if (v_mkdir(dst.c_str(), perm) != 0)
            return 1;
    } else if (S_ISCHR(ss.st_mode) || S_ISBLK(ss.st_mode)) {
        // XXX special handling for /dev/ptmx; there is probably a
        // cleaner way
        if (x_rm_f(dst))
            return 1;
        if (src.length() == 9 && src == "/dev/ptmx")
            return x_symlink("pts/ptmx", dst.c_str());
        mode_t mode = ss.st_mode & (S_IFREG | S_IFCHR | S_IFBLK | S_IFIFO | S_IFSOCK | S_ISUID | S_ISGID | S_IRWXU | S_IRWXG | S_IRWXO);
        if (x_mknod(dst.c_str(), mode, ss.st_rdev))
            return 1;
    } else if (S_ISLNK(ss.st_mode)) {
        if (x_rm_f(dst))
            return 1;
        char lnkbuf[4096];
        ssize_t r = readlink(src.c_str(), lnkbuf, sizeof(lnkbuf));
        if (r == -1)
            return perror_fail("readlink %s: %s\n", src.c_str());
        else if (r == sizeof(lnkbuf))
            return perror_fail("%s: Symbolic link too long\n", src.c_str());
        lnkbuf[r] = 0;
        if (x_symlink(lnkbuf, dst.c_str()))
            return 1;
        if (x_copy_utimes(dst.c_str(), ss))
            return 1;
        handle_symlink_dst(dst, src, std::string(lnkbuf), jaildev);
    } else
        // cannot deal
        return perror_fail("%s: Odd file type\n", src.c_str());

    if (ss.st_uid != ROOT || ss.st_gid != ROOT)
        return x_lchown(dst.c_str(), ss.st_uid, ss.st_gid);
    return 0;
}

static int handle_copy(std::string src, std::string subdst,
                       int flags, dev_t jaildev) {
    static std::string last_parentdir;

    assert(subdst[0] == '/');
    assert(subdst.length() == 1 || subdst[1] != '/');
    assert(dstroot.back() != '/');
    assert(subdst.substr(0, dstroot.length()) != dstroot);

    // do not end in slash. lstat() on a symlink path actually follows the
    // symlink if the path ends in slash
    while (src.length() > 1 && src.back() == '/') {
        src = src.substr(0, src.length() - 1);
    }
    while (subdst.length() > 1 && subdst.back() == '/') {
        subdst = subdst.substr(0, subdst.length() - 1);
    }

    std::string dst = dstroot + subdst;
    if (dst_table.find(dst) != dst_table.end()) {
        return 1;
    }
    dst_table[dst] = 1;

    struct stat ss;

    std::string dst_parentdir = path_noendslash(path_parentdir(dst));
    if (dst_parentdir != last_parentdir
        && dst_parentdir.length() > dstroot.length()) {
        last_parentdir = dst_parentdir;
        if (dst_table.find(last_parentdir) == dst_table.end()) {
            int r = handle_copy(path_noendslash(path_parentdir(src)),
                                last_parentdir.substr(dstroot.length()),
                                0, jaildev);
            if (r != 0) {
                return r;
            }
        }
    }

    if (lstat(src.c_str(), &ss) != 0) {
        return perror_fail("lstat %s: %s\n", src.c_str());
    }

    // set up skeleton directory version
    if (!linkdir.empty()) {
        do_copy(linkdir + subdst, src, ss, true, jaildev);
    }

    if (do_copy(dst, src, ss, !(flags & FLAG_CP), jaildev)) {
        return 1;
    }

    if (S_ISDIR(ss.st_mode)) {
        return handle_mount(src, dst, false);
    }
    return 0;
}

inline const char* opt_wordskip(const char* s) {
    while (*s != ']' && *s != ';' && !isspace((unsigned char) *s))
        ++s;
    return s;
}

inline bool opt_eq(const char* opt, const char* endopt,
                   const char* def, unsigned len) {
    return endopt - opt == len && memcmp(opt, def, len) == 0;
}

static std::string file_get_contents_error(std::string msg, int errorness) {
    if (errorness > 0)
        fprintf(stderr, "%s\n", msg.c_str());
    if (errorness > 1)
        exit(1);
    return "";
}

static std::string file_get_contents(std::string fname, int errorness) {
    FILE* f;
    if (fname == "-") {
        f = stdin;
        if (isatty(STDIN_FILENO)) {
            return file_get_contents_error("stdin: Is a tty", errorness);
        }
    } else {
        f = fopen(fname.c_str(), "r");
        if (!f) {
            return file_get_contents_error(fname + ": " + strerror(errno), errorness);
        }
    }
    std::string contents;
    while (!feof(f) && !ferror(f)) {
        char buf[BUFSIZ];
        size_t n = fread(buf, 1, BUFSIZ, f);
        if (n > 0) {
            contents.append(buf, n);
        }
    }
    if (ferror(f)) {
        return file_get_contents_error(fname + ": " + strerror(errno), errorness);
    }
    fclose(f);
    return contents;
}

static void fix_jail_bind_src(dev_t jaildev,
                              std::string src, std::string want_tag,
                              std::string want_files) {
    std::string srcx = path_endslash(src) + ".pa-jail-bindtag";
    if (verbose) {
        fprintf(verbosefile, "test %s = `cat %s`\n", shell_quote(want_tag).c_str(), shell_quote(srcx).c_str());
    }
    std::string got_tag = file_get_contents(srcx, 0);
    while (!got_tag.empty() && isspace((unsigned char) got_tag.back())) {
        got_tag.pop_back();
    }
    if (got_tag != want_tag) {
        std::string contents = file_get_contents(want_files, 2);
        std::string old_dstroot = dstroot;
        dstroot = path_noendslash(src);
        construct_jail(jaildev, contents, true);
        dstroot = old_dstroot;
        if (verbose) {
            fprintf(verbosefile, "echo %s > %s\n", shell_quote(want_tag).c_str(), srcx.c_str());
        }
        if (!dryrun) {
            want_tag += "\n";
            int fd = open(srcx.c_str(), O_WRONLY | O_CREAT | O_TRUNC | O_NOFOLLOW, 0600);
            if (fd == -1
                || (size_t) write(fd, want_tag.data(), want_tag.length()) != want_tag.length()) {
                perror_die(srcx.c_str());
            }
            close(fd);
        }
    }
}

static int construct_jail(dev_t jaildev, std::string& str, bool nomount) {
    // prepare root
    if (x_chmod(dstroot.c_str(), 0755)
        || x_lchown(dstroot.c_str(), 0, 0)) {
        return 1;
    }
    dst_table[dstroot + "/"] = 1;

    // Mounts
    populate_mount_table();

    // Read a line at a time
    std::string cursrcdir("/"), curdstsubdir("/");
    std::string bind_tag, bind_files, mount_dst, mount_args;
    int base_flags = 0;

    const char* pos = str.data(), *endpos = pos + str.length();
    while (pos < endpos) {
        while (pos < endpos && isspace((unsigned char) *pos)) {
            ++pos;
        }
        const char* line = pos;
        while (pos < endpos && *pos != '\n') {
            ++pos;
        }
        const char* endline = pos;
        while (line < endline && isspace((unsigned char) endline[-1])) {
            --endline;
        }
        if (line == endline || line[0] == '#') {
            continue;
        }

        // 'directory:'
        if (endline[-1] == ':') {
            if (line + 2 == endline && line[0] == '.') {
                cursrcdir = std::string("/");
            } else if (line + 2 > endline && line[0] == '.' && line[1] == '/') {
                cursrcdir = std::string(line + 1, endline - 1);
            } else {
                cursrcdir = std::string(line, endline - 1);
            }
            if (cursrcdir[0] != '/') {
                cursrcdir = std::string("/") + cursrcdir;
            }
            while (cursrcdir.length() > 1
                   && cursrcdir[cursrcdir.length() - 1] == '/'
                   && cursrcdir[cursrcdir.length() - 2] == '/') {
                cursrcdir = cursrcdir.substr(0, cursrcdir.length() - 1);
            }
            if (cursrcdir[cursrcdir.length() - 1] != '/') {
                cursrcdir += '/';
            }
            curdstsubdir = cursrcdir;
            assert(curdstsubdir.back() == '/');
            continue;
        }

        // '[FLAGS]'
        int flags = base_flags;
        if (endline[-1] == ']') {
            // skip ' [FLAGS]'
            for (--endline; line < endline && endline[-1] != '['; --endline) {
                // do nothing
            }
            if (line == endline) {
                continue;
            }
            const char* opts = endline;
            do {
                --endline;
            } while (line < endline && isspace((unsigned char) endline[-1]));
            // parse flags
            while (true) {
                while (isspace((unsigned char) *opts) || *opts == ';') {
                    ++opts;
                }
                if (*opts == ']') {
                    break;
                }
                // read first option word
                const char* optstart = opts;
                opts = opt_wordskip(opts + 1);
                // process option
                int want = 0;
                if (opt_eq(optstart, opts, "cp", 2)) {
                    flags |= FLAG_CP;
                } else if (opt_eq(optstart, opts, "bind", 4)) {
                    flags |= FLAG_BIND;
                    want = FLAG_BIND;
                } else if (opt_eq(optstart, opts, "bind-ro", 7)) {
                    flags |= FLAG_BIND_RO;
                    want = FLAG_BIND;
                } else if (opt_eq(optstart, opts, "mount", 5)) {
                    flags |= FLAG_MOUNT;
                    want = FLAG_MOUNT;
                }
                if (want == FLAG_BIND) {
                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    const char* tagstart = opts;
                    opts = opt_wordskip(opts);
                    bind_tag = std::string(tagstart, opts);

                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    tagstart = opts;
                    opts = opt_wordskip(opts);
                    bind_files = std::string(tagstart, opts);
                } else if (want == FLAG_MOUNT) {
                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    const char* mountstart = opts;
                    opts = opt_wordskip(opts);
                    mount_dst = std::string(mountstart, opts);

                    while (isspace((unsigned char) *opts)) {
                        ++opts;
                    }
                    mountstart = opts;
                    while (*opts != ']' && *opts != ';') {
                        ++opts;
                    }
                    mount_args = std::string(mountstart, opts);
                }
                // skip to next option word
                while (*opts != ']' && *opts != ';') {
                    ++opts;
                }
            }
        }

        std::string src, dst;
        const char* arrow = (const char*) memmem(line, endline - line, " <- ", 4);
        if (arrow) {
            src = std::string(arrow + 4, endline);
        } else if (line[0] == '/') {
            src = std::string(line, endline);
        } else {
            src = cursrcdir + std::string(line, endline);
        }
        if (!arrow) {
            arrow = endline;
        }
        dst = curdstsubdir + std::string(line + (line[0] == '/'), arrow);

        // act on flags
        if (flags & (FLAG_BIND | FLAG_BIND_RO)) {
            if (!nomount) {
                if (flags & FLAG_MOUNT) {
                    fprintf(stderr, "%s: [mount] option ignored\n", src.c_str());
                }
                if (!bind_tag.empty() && !bind_files.empty()) {
                    fix_jail_bind_src(jaildev, src, bind_tag, bind_files);
                }
                mountslot ms(src.c_str(), "none",
                             flags & FLAG_BIND_RO ? "bind,rec,unbindable,ro" : "bind,rec,unbindable");
                ms.wanted = true;
                mount_table[src] = ms;
                v_ensuredir(dstroot + dst, 0555, true);
                handle_mount(src, dstroot + dst, false);
            }
        } else if (flags & FLAG_MOUNT) {
            if (!nomount) {
                mountslot ms(src.c_str(), mount_dst.c_str(), mount_args.c_str());
                ms.wanted = true;
                mount_table[src] = ms;
                v_ensuredir(dstroot + dst, 0555, true);
                handle_mount(src, dstroot + dst, false);
            }
        } else {
            handle_copy(src, dst, flags, jaildev);
        }
    }

    return exit_value;
}


// pa-jail.conf

struct pajailconf {
    pajailconf();
    pajailconf(const std::string& str);

    bool allow_jail(const std::string& dir) const {
        return allows_type("jail", dir, false);
    }
    bool allow_jail_subdir(const std::string& dir) const {
        return allows_type("jail", dir, true);
    }
    bool allow_skeleton(const std::string& dir) const {
        return allows_type("skeleton", dir, false);
    }
    const std::string& treedir() const {
        return treedir_;
    }
    std::string disable_message() const {
        if (!allowance_pattern_.empty()) {
            return "  (disabled by " + allowance_pattern_ + ")\n";
        } else {
            return std::string();
        }
    }
private:
    char buf_[8192];
    size_t len_;
    mutable std::string treedir_;
    mutable std::string allowance_pattern_;

    std::pair<const char*, const char*> take_word(size_t& pos) const;
    bool allows_type(const char* type, std::string dir, bool superdir) const;
    void set_treedir(std::string pattern, const std::string& dir, bool is_explicit) const;
};

static bool writable_only_by_root(const struct stat& st) {
    return st.st_uid == ROOT
        && (st.st_gid == ROOT || !(st.st_mode & S_IWGRP))
        && !(st.st_mode & S_IWOTH);
}

pajailconf::pajailconf() {
    int fd = open("/etc/pa-jail.conf", O_RDONLY | O_NOFOLLOW);
    if (fd == -1) {
        perror_die("/etc/pa-jail.conf");
    }

    struct stat st;
    if (fstat(fd, &st) != 0) {
        perror_die("/etc/pa-jail.conf");
    } else if (!writable_only_by_root(st)) {
        die("/etc/pa-jail.conf: Writable by non-root\n");
    }

    ssize_t nr = read(fd, buf_, sizeof(buf_));
    if (nr < 0) {
        perror_die("/etc/pa-jail.conf");
    } else if (nr == 0) {
        die("/etc/pa-jail.conf: Empty file\n");
    } else if (nr == sizeof(buf_)) {
        die("/etc/pa-jail.conf: Too big, max %zu bytes\n", sizeof(buf_));
    }
    len_ = nr;

    close(fd);
}

pajailconf::pajailconf(const std::string& s) {
    if (s.length() >= sizeof(buf_)) {
        die("pajailconf: String too big, max %zu bytes\n", sizeof(buf_));
    }
    memcpy(buf_, s.data(), s.length());
    len_ = s.length();
}

std::pair<const char*, const char*> pajailconf::take_word(size_t& pos) const {
    while (pos < len_
           && buf_[pos] != '\n'
           && isspace((unsigned char) buf_[pos])) {
        ++pos;
    }
    const char* a = &buf_[pos];
    while (pos < len_ && !isspace((unsigned char) buf_[pos])) {
        ++pos;
    }
    return std::make_pair(a, &buf_[pos]);
}

static bool check_action(const std::pair<const char*, const char*>& action,
                         const char* prefix,
                         const char* type, size_t typelen) {
    return size_t(action.second - action.first) == strlen(prefix) + typelen
        && memcmp(action.first, prefix, strlen(prefix)) == 0
        && memcmp(action.second - typelen, type, typelen) == 0;
}

static bool check_dirmatch(const std::string& pattern,
                           std::string str,
                           bool superdir = false,
                           std::string* store_superdir = nullptr) {
    if (superdir) {
        size_t patslashpos = 0, strslashpos = 0;
        while (true) {
            patslashpos = pattern.find('/', patslashpos);
            if (patslashpos == std::string::npos) {
                str = str.substr(0, strslashpos);
                if (store_superdir) {
                    *store_superdir = str;
                }
                break;
            }
            ++patslashpos;
            strslashpos = str.find('/', strslashpos);
            if (strslashpos == std::string::npos) {
                return false;
            }
            ++strslashpos;
        }
    }
    return fnmatch(pattern.c_str(), str.c_str(),
                   FNM_PATHNAME | FNM_PERIOD) == 0;
}

void pajailconf::set_treedir(std::string pattern,
                             const std::string& str,
                             bool is_explicit) const {
    if (!is_explicit
        && pattern.length() > 3
        && memcmp(pattern.data() + pattern.length() - 3, "/*/", 3) == 0) {
        pattern = pattern.substr(0, pattern.length() - 2);
    }
    std::string superdir;
    if (check_dirmatch(pattern, str, true, &superdir)
        && (treedir_.empty() || treedir_.length() > superdir.length())) {
        treedir_ = superdir;
    }
}

bool pajailconf::allows_type(const char* type,
                             std::string dir,
                             bool superdir) const {
    size_t pos = 0, typelen = strlen(type);
    int allowed_globally = -1, allowed_locally = -1;
    allowance_pattern_ = treedir_ = std::string();
    dir = path_endslash(dir);

    while (pos < len_) {
        auto action = take_word(pos);
        auto arg = take_word(pos);
        while (pos < len_ && buf_[pos] != '\n') {
            take_word(pos);
        }
        while (pos < len_ && buf_[pos] == '\n') {
            ++pos;
        }

        // check action
        int allowed;
        if (check_action(action, "disable", type, typelen)
            || check_action(action, "no", type, typelen)) {
            allowed = 0;
        } else if (check_action(action, "enable", type, typelen)
                   || check_action(action, "allow", type, typelen)) {
            allowed = 1;
        } else if (check_action(action, "treedir", "", 0)) {
            if (arg.first != arg.second && arg.first[0] == '/') {
                auto pattern = path_endslash(std::string(arg.first, arg.second));
                set_treedir(pattern, dir, true);
            }
            continue;
        } else {
            continue;
        }

        if (arg.first == arg.second) {
            // global allowance
            allowed_globally = allowed;
            if (!allowed) {
                allowed_locally = allowed;
            }
            allowance_pattern_ = std::string();
        } else if (arg.first[0] == '/') {
            // check subdirectory match
            auto pattern = path_endslash(std::string(arg.first, arg.second));
            if (check_dirmatch(pattern, dir, superdir || allowed <= 0)) {
                allowed_locally = allowed;
                allowance_pattern_ = pattern;
                if (allowed > 0) {
                    set_treedir(pattern, dir, false);
                }
            }
        }
    }

    return allowed_globally != 0 && allowed_locally > 0;
}

#if 0
struct pajailconf_tester {
    pajailconf_tester() {
        pajailconf jc("enablejail /jails/run*\nenablejail /jails/~*\n");
        assert(jc.allow_jail("/jails/run"));
        assert(jc.treedir() == "/jails/run/");
        assert(jc.allow_jail("/jails/run/"));
        assert(jc.treedir() == "/jails/run/");
        assert(!jc.allow_jail("/jails"));
        assert(!jc.allow_jail("/jails/"));
        assert(!jc.allow_jail("/jails/runa/runb"));
        assert(!jc.allow_jail("/jails/runa/runb/"));
        assert(jc.allow_jail_subdir("/jails/runa/runb"));
        assert(jc.allow_jail_subdir("/jails/runa/runb/"));
        assert(jc.allow_jail("/jails/runa"));
        assert(jc.treedir() == "/jails/runa/");
        assert(jc.allow_jail("/jails/runa/"));
        assert(jc.treedir() == "/jails/runa/");
        assert(jc.allow_jail("/jails/~runa"));
        assert(jc.treedir() == "/jails/~runa/");
        assert(jc.allow_jail("/jails/~runa/"));
        assert(jc.treedir() == "/jails/~runa/");

        jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ndisablejail /\n");
        assert(!jc.allow_jail("/jails/run"));
        assert(!jc.allow_jail("/jails/run/"));
        assert(!jc.allow_jail("/jails"));
        assert(!jc.allow_jail("/jails/"));
        assert(!jc.allow_jail("/jails/runa/runb"));
        assert(!jc.allow_jail("/jails/runa/runb/"));
        assert(!jc.allow_jail("/jails/runa"));
        assert(!jc.allow_jail("/jails/runa/"));
        assert(!jc.allow_jail("/jails/~runa"));
        assert(!jc.allow_jail("/jails/~runa/"));

        jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ndisablejail /jails/runa\n");
        assert(jc.allow_jail("/jails/run"));
        assert(jc.allow_jail("/jails/run/"));
        assert(!jc.allow_jail("/jails"));
        assert(!jc.allow_jail("/jails/"));
        assert(!jc.allow_jail("/jails/runa/runb"));
        assert(!jc.allow_jail("/jails/runa/runb/"));
        assert(!jc.allow_jail("/jails/runa"));
        assert(!jc.allow_jail("/jails/runa/"));
        assert(jc.allow_jail("/jails/~runa"));
        assert(jc.allow_jail("/jails/~runa/"));

        jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ntreedir /jails\n");
        assert(jc.allow_jail("/jails/run"));
        assert(jc.allow_jail("/jails/run/"));
        assert(jc.treedir() == "/jails/");
        assert(!jc.allow_jail("/jails"));
        assert(!jc.allow_jail("/jails/"));
        assert(!jc.allow_jail("/jails/runa/runb"));
        assert(!jc.allow_jail("/jails/runa/runb/"));
        assert(jc.allow_jail("/jails/runa"));
        assert(jc.allow_jail("/jails/runa/"));
        assert(jc.treedir() == "/jails/");
        assert(jc.allow_jail("/jails/~runa"));
        assert(jc.allow_jail("/jails/~runa/"));
        assert(jc.treedir() == "/jails/");

        jc = pajailconf("enablejail /jails/run*\nenablejail /jails/~*\ntreedir /hails\n");
        assert(jc.allow_jail("/jails/run"));
        assert(jc.allow_jail("/jails/run/"));
        assert(jc.treedir() == "/jails/run/");
        assert(!jc.allow_jail("/jails"));
        assert(!jc.allow_jail("/jails/"));
        assert(!jc.allow_jail("/jails/runa/runb"));
        assert(!jc.allow_jail("/jails/runa/runb/"));
        assert(jc.allow_jail("/jails/runa"));
        assert(jc.allow_jail("/jails/runa/"));
        assert(jc.treedir() == "/jails/runa/");
        assert(jc.allow_jail("/jails/~runa"));
        assert(jc.allow_jail("/jails/~runa/"));
        assert(jc.treedir() == "/jails/~runa/");
    }
};
static pajailconf_tester tester;
#endif


// main program

static std::string check_filename(std::string name) {
    const char *allowed_chars = "/0123456789-._ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz~";
    char buf[1024];

    if (strspn(name.c_str(), allowed_chars) != name.length()
        || name.empty()
        || name[0] == '~'
        || name.length() >= sizeof(buf))
        return std::string();

    char* out = buf;
    for (const char* s = name.c_str(); *s; ++s) {
        *out++ = *s;
        if (*s == '.' && (s[1] == '/' || s[1] == '\0')
            && s != name.c_str() && s[-1] == '/') {
            --out;
            ++s;
        } else if (*s == '.' && s[1] == '.' && (s[2] == '/' || s[2] == '\0')
                   && (s == name.c_str() || s[-1] == '/'))
            return std::string();
        while (*s == '/' && s[1] == '/')
            ++s;
    }
    while (out > buf + 1 && out[-1] == '/')
        --out;
    *out = '\0';
    return std::string(buf, out - buf);
}

static std::string absolute(const std::string& dir) {
    if (!dir.empty() && dir[0] == '/')
        return dir;
    char buf[BUFSIZ];
    if (getcwd(buf, BUFSIZ - 1) == NULL)
        perror_die("getcwd");
    char* endbuf = buf + strlen(buf);
    while (endbuf - buf > 1 && endbuf[-1] == '/')
        --endbuf;
    memcpy(endbuf, "/", 2);
    return std::string(buf) + dir;
}

struct jaildirinfo {
    std::string dir;
    std::string parent;
    int parentfd;
    std::string component;
    bool allowed;
    std::string permdir;
    dev_t dev;
    std::string skeletondir;

    jaildirinfo(const char* str, const std::string& skeletondir,
                jailaction action, pajailconf& jailconf);
    void check();
    void chown_home();
    void chown_recursive(const std::string& dir, uid_t owner, gid_t group);
    void remove();

private:
    void chown_recursive(int dirfd, std::string& dirbuf, uid_t owner,
                         gid_t group, bool ishome, dev_t dev);
    void remove_recursive(int dirfd, std::string component, std::string name);
};

jaildirinfo::jaildirinfo(const char* str, const std::string& skeletonstr,
                         jailaction action, pajailconf& jailconf)
    : dir(check_filename(absolute(str))),
      parentfd(-1), allowed(false), dev(-1),
      skeletondir(skeletonstr) {
    if (dir.empty() || dir == "/" || dir[0] != '/') {
        fprintf(stderr, "%s: Bad characters in filename\n", str);
        exit(1);
    }
    dir = path_endslash(dir);
    if (jailconf.allow_jail(dir)) {
        permdir = jailconf.treedir();
    } else {
        die("%s: Jail disabled by /etc/pa-jail.conf\n%s",
            dir.c_str(), jailconf.disable_message().c_str());
    }

    if (!skeletondir.empty()) {
        skeletondir = path_endslash(absolute(skeletondir));
        if (!jailconf.allow_skeleton(skeletondir)) {
            die("%s: Skeleton disabled by /etc/pa-jail.conf\n%s",
                skeletondir.c_str(), jailconf.disable_message().c_str());
        }
    }

    size_t last_pos = 0;
    int fd = -1;
    bool dryrunning = false;
    while (last_pos != dir.length()) {
        // extract component
        size_t next_pos = last_pos;
        while (next_pos && next_pos < dir.length() && dir[next_pos] != '/') {
            ++next_pos;
        }
        if (!next_pos) {
            ++next_pos;
        }
        parent = dir.substr(0, last_pos);
        component = dir.substr(last_pos, next_pos - last_pos);
        std::string thisdir = dir.substr(0, next_pos);
        last_pos = next_pos;
        while (last_pos != dir.length() && dir[last_pos] == '/') {
            ++last_pos;
        }

        // check whether we are below the permission directory
        bool allowed_here = !permdir.empty()
            && last_pos >= permdir.length()
            && dir.substr(0, permdir.length()) == permdir;

        // open it and swap it in
        if (parentfd >= 0) {
            close(parentfd);
        }
        parentfd = fd;
        fd = openat(parentfd, component.c_str(), O_PATH | O_CLOEXEC | O_NOFOLLOW);
        if (fd == -1 && !allowed_here && errno == ENOENT) {
            break;
        }
        if ((fd == -1 && dryrunning)
            || (fd == -1 && allowed_here && errno == ENOENT
                && (action == do_add || action == do_run))) {
            if (v_mkdirat(parentfd, component.c_str(), 0755, thisdir) != 0) {
                fprintf(stderr, "mkdir %s: %s\n", thisdir.c_str(), strerror(errno));
                exit(1);
            }
            dirtable.insert(std::make_pair(thisdir, 0));
            fd = openat(parentfd, component.c_str(), O_CLOEXEC | O_NOFOLLOW);
            // turn off suid+sgid on created root directory
            if (last_pos == dir.length() && (fd >= 0 || dryrun)
                && v_fchmod(fd, 0755, thisdir) != 0) {
                fprintf(stderr, "chmod %s: %s\n", thisdir.c_str(), strerror(errno));
                exit(1);
            }
            if (dryrun) {
                dryrunning = true;
                continue;
            }
        }
        if (fd == -1 && errno == ENOENT && action == do_rm && doforce) {
            exit(0);
        } else if (fd == -1) {
            fprintf(stderr, "%s: %s\n", thisdir.c_str(), strerror(errno));
            exit(1);
        }

        // stat it
        struct stat s;
        if (fstat(fd, &s) != 0) {
            perror_die(thisdir);
        }
        if (!S_ISDIR(s.st_mode)) {
            errno = ENOTDIR;
            perror_die(thisdir);
        } else if (!allowed_here && last_pos != dir.length()) {
            if (s.st_uid != ROOT) {
                die("%s: Not owned by root\n", thisdir.c_str());
            } else if ((s.st_gid != ROOT && (s.st_mode & S_IWGRP))
                       || (s.st_mode & S_IWOTH)) {
                die("%s: Writable by non-root\n", thisdir.c_str());
            }
        }
        dev = s.st_dev;
    }
    if (fd >= 0) {
        close(fd);
    }
}

void jaildirinfo::check() {
    assert(!permdir.empty() && permdir[permdir.length() - 1] == '/');
    assert(dir.substr(0, permdir.length()) == permdir);
}

void jaildirinfo::chown_home() {
    populate_mount_table();
    std::string dirbuf = dir + "home/";
    int dirfd = openat(parentfd, (component + "/home").c_str(),
                       O_CLOEXEC | O_NOFOLLOW);
    struct stat dirst;
    if (dirfd == -1 || fstat(dirfd, &dirst) != 0) {
        perror_die(dirbuf);
    }
    chown_recursive(dirfd, dirbuf, ROOT, ROOT, true, dirst.st_dev);
}

void jaildirinfo::chown_recursive(const std::string& dir,
                                  uid_t owner, gid_t group) {
    std::string dirbuf = path_endslash(dir);
    int dirfd = open(dir.c_str(), O_CLOEXEC | O_NOFOLLOW);
    struct stat dirst;
    if (dirfd == -1 || fstat(dirfd, &dirst) != 0) {
        perror_die(dirbuf);
    }
    if (x_fchown(dirfd, owner, group, dirbuf)) {
        exit(exit_value);
    }
    chown_recursive(dirfd, dirbuf, owner, group, false, dirst.st_dev);
}

void jaildirinfo::chown_recursive(int dirfd, std::string& dirbuf,
                                  uid_t owner, gid_t group,
                                  bool ishome, dev_t dev) {
    dirbuf = path_endslash(dirbuf);
    size_t dirbuflen = dirbuf.length();

    typedef std::pair<uid_t, gid_t> ug_t;
    std::unordered_map<std::string, ug_t>* home_map = nullptr;
    if (ishome) {
        setpwent();
        home_map = new std::unordered_map<std::string, ug_t>;
        while (struct passwd* pw = getpwent()) {
            std::string name;
            if (pw->pw_dir && strncmp(pw->pw_dir, "/home/", 6) == 0
                && strchr(pw->pw_dir + 6, '/') == NULL) {
                name = pw->pw_dir + 6;
            } else {
                name = pw->pw_name;
            }
            (*home_map)[name] = ug_t(pw->pw_uid, pw->pw_gid);
        }
    }

    DIR* dir = fdopendir(dirfd);
    if (!dir)
        perror_die(dirbuf);

    struct dirent* de;
    while ((de = readdir(dir))) {
        if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0) {
            continue;
        }

        // don't follow symbolic links
        if (de->d_type == DT_LNK) {
            if (x_lchownat(dirfd, de->d_name, owner, group, dirbuf)) {
                exit(exit_value);
            }
            continue;
        }

        // look up uid/gid if in home
        uid_t u = owner;
        gid_t g = group;
        if (home_map) {
            auto it = home_map->find(de->d_name);
            if (it != home_map->end()) {
                u = it->second.first, g = it->second.second;
            }
        }

        // recurse
        if (de->d_type == DT_DIR) {
            dirbuf += de->d_name;
            auto it = mount_table.find(dirbuf);
            if (it == mount_table.end()) { // not a mount point
                int subdirfd = openat(dirfd, de->d_name, O_CLOEXEC | O_NOFOLLOW);
                struct stat subdirst;
                if (subdirfd == -1 || fstat(subdirfd, &subdirst) != 0) {
                    perror_die(dirbuf);
                }
                if (subdirst.st_dev == dev) {
                    if (x_fchown(subdirfd, u, g, dirbuf)) {
                        exit(exit_value);
                    }
                    chown_recursive(subdirfd, dirbuf, u, g, false, dev);
                }
            }
            dirbuf.resize(dirbuflen);
        } else if (x_lchownat(dirfd, de->d_name, u, g, dirbuf)) {
            exit(exit_value);
        }
    }

    closedir(dir);
    delete home_map;
}

void jaildirinfo::remove() {
    remove_recursive(parentfd, component, path_endslash(dir));
}

void jaildirinfo::remove_recursive(int parentdirfd, std::string component,
                                   std::string dirname) {
    auto it = dst_table.find(dirname);
    if (it != dst_table.end() && it->second == 3) { // unmounted file system
        return;
    }

    int dirfd = openat(parentdirfd, component.c_str(), O_RDONLY);
    struct stat dirst;
    if (dirfd == -1 || fstat(dirfd, &dirst) != 0) {
        perror_die(dirname);
    }
    if (dirst.st_dev != dev) { // --one-file-system
        close(dirfd);
        return;
    }

    DIR* dir = fdopendir(dirfd);
    if (!dir) {
        perror_die(dirname);
    }
    while (struct dirent* de = readdir(dir)) {
        if (de->d_type == DT_DIR) {
            if (strcmp(de->d_name, ".") == 0 || strcmp(de->d_name, "..") == 0) {
                continue;
            }
            std::string next_component = de->d_name;
            std::string next_dirname = dirname + next_component;
            remove_recursive(dirfd, next_component, dirname + next_component);
        } else {
            if (verbose) {
                fprintf(verbosefile, "rm %s%s\n", dirname.c_str(), de->d_name);
            }
            if (!dryrun && unlinkat(dirfd, de->d_name, de->d_type == DT_DIR ? AT_REMOVEDIR : 0) != 0) {
                perror_die("rm " + dirname + de->d_name);
            }
        }
    }
    closedir(dir);
    close(dirfd);

    if (verbose) {
        fprintf(verbosefile, "rmdir %s\n", dirname.c_str());
    }
    if (!dryrun && unlinkat(parentdirfd, component.c_str(), AT_REMOVEDIR) != 0) {
        perror_die("rmdir " + dirname);
    }
}



class jailownerinfo {
  public:
    uid_t owner_;
    gid_t group_;
    std::string owner_home_;
    std::string owner_sh_;

    jailownerinfo();
    ~jailownerinfo();
    void init(const char* owner_name);
    void exec(int argc, char** argv, jaildirinfo& jaildir,
              int inputfd, double timeout, double idle_timeout, bool foreground);
    int exec_go();

  private:
    std::vector<const char*> newenv_;
    char** argv_;
    jaildirinfo* jaildir_;
    int inputfd_;
    struct timeval start_time_;
    struct timeval expiry_;
    double idle_timeout_;
    struct timeval active_time_;
    struct timeval idle_expiry_;
    struct buffer {
        unsigned char* buf_;
        size_t head_;
        size_t tail_;
        size_t end_;
        size_t cap_;
        bool input_closed_;
        bool output_closed_;
        int rerrno_;
        buffer(size_t cap)
            : buf_(new unsigned char[cap]), head_(0), tail_(0), end_(0), cap_(cap),
              input_closed_(false), output_closed_(false), rerrno_(0) {
        }
        ~buffer() {
            delete[] buf_;
        }
        bool transfer_in(int from);
        bool transfer_out(int to);
        bool done();
    };
    buffer to_slave_;
    buffer from_slave_;
    bool stdin_tty_;
    bool stdout_tty_;
    bool stderr_tty_;
    int ttyfd_;
    struct termios ttyfd_termios_;
    int child_status_;
    bool has_blocked_;

    void start_sigpipe();
    void block(int ptymaster);
    int check_child_timeout(pid_t child, bool waitpid);
    void wait_background(pid_t child, int ptymaster);
    void write_timing();
    void exec_done(pid_t child, int exit_status) __attribute__((noreturn));
};

jailownerinfo::jailownerinfo()
    : owner_(ROOT), group_(ROOT), argv_(),
      to_slave_(4096), from_slave_(8192), child_status_(-1) {
    stdin_tty_ = isatty(STDIN_FILENO);
    stdout_tty_ = isatty(STDOUT_FILENO);
    stderr_tty_ = isatty(STDERR_FILENO);
    // Assume all tty-opened fds are to the same tty.
    if (stdin_tty_ || stdout_tty_ || stderr_tty_) {
        ttyfd_ = stdin_tty_ ? STDIN_FILENO : (stdout_tty_ ? STDOUT_FILENO : STDERR_FILENO);
        tcgetattr(ttyfd_, &ttyfd_termios_);
    } else {
        ttyfd_ = -1;
    }
}

jailownerinfo::~jailownerinfo() {
    delete[] argv_;
}

static bool check_shell(const char* shell) {
    bool found = false;
    char* sh;
    while (!found && (sh = getusershell())) {
        found = strcmp(sh, shell) == 0;
    }
    endusershell();
    return found;
}

void jailownerinfo::init(const char* owner_name) {
    if (strlen(owner_name) >= 1024) {
        die("%s: Username too long\n", owner_name);
    }

    struct passwd* pwnam = getpwnam(owner_name);
    if (!pwnam) {
        die("%s: No such user\n", owner_name);
    }

    owner_ = pwnam->pw_uid;
    group_ = pwnam->pw_gid;
    if (strcmp(pwnam->pw_dir, "/") == 0) {
        owner_home_ = "/home/nobody";
    } else if (strncmp(pwnam->pw_dir, "/home/", 6) == 0) {
        owner_home_ = pwnam->pw_dir;
    } else {
        die("%s: Home directory %s not under /home\n", owner_name, pwnam->pw_dir);
    }

    if (strcmp(pwnam->pw_shell, "/bin/bash") == 0
        || strcmp(pwnam->pw_shell, "/bin/sh") == 0
        || check_shell(pwnam->pw_shell)) {
        owner_sh_ = pwnam->pw_shell;
    } else {
        die("%s: Shell %s not allowed by /etc/shells\n", owner_name, pwnam->pw_shell);
    }

    if (owner_ == ROOT) {
        die("%s: Jail user cannot be root\n", owner_name);
    }
}

#if __linux__
extern "C" {
static int exec_clone_function(void* arg) {
    jailownerinfo* jailowner = static_cast<jailownerinfo*>(arg);
    return jailowner->exec_go();
}
}
#endif

static void write_pid(int p) {
    if (pidfd >= 0) {
        lseek(pidfd, 0, SEEK_SET);
        char buf[1024], *sx = buf;
        const char* s0 = pidcontents.data(), *s1 = s0 + pidcontents.length();
        while (s0 != s1 && sx != buf + 1024) {
            if (*s0 == '$' && s0 + 1 != s1 && s0[1] == '$') {
                int l = snprintf(sx, buf + 1024 - sx, "%d", p);
                sx += std::min(ssize_t(l), buf + 1024 - sx);
                s0 += 2;
            } else {
                *sx++ = *s0++;
            }
        }
        ssize_t w = write(pidfd, buf, sx - buf);
        if (w != ssize_t(sx - buf) || ftruncate(pidfd, w) != 0) {
            perror_die(pidfilename);
        }
    }
}

static struct timeval timer_add_delay(struct timeval tv, double delay) {
    struct timeval delta;
    delta.tv_sec = (long) delay;
    delta.tv_usec = (long) ((delay - delta.tv_sec) * 1000000);
    timeradd(&tv, &delta, &tv);
    return tv;
}

static int timer_difference_ms(const struct timeval& lhs, struct timeval rhs) {
    timersub(&lhs, &rhs, &rhs);
    return rhs.tv_sec * 1000 + rhs.tv_usec / 1000;
}

void jailownerinfo::exec(int argc, char** argv, jaildirinfo& jaildir,
                         int inputfd, double timeout, double idle_timeout,
                         bool foreground) {
    // adjust environment; make sure we have a PATH
    char homebuf[8192];
    sprintf(homebuf, "HOME=%s", owner_home_.c_str());
    const char* path = "PATH=/usr/local/bin:/bin:/usr/bin";
    const char* lang = "LANG=C";
    const char* term = nullptr;
    const char* ld_library_path = nullptr;
    {
        extern char** environ;
        for (char** eptr = environ; *eptr; ++eptr) {
            if (strncmp(*eptr, "PATH=", 5) == 0) {
                path = *eptr;
            } else if (strncmp(*eptr, "LANG=", 5) == 0) {
                lang = *eptr;
            } else if (strncmp(*eptr, "TERM=", 5) == 0) {
                term = *eptr;
            } else if (strncmp(*eptr, "LD_LIBRARY_PATH=", 16) == 0) {
                ld_library_path = *eptr;
            }
        }
    }
    newenv_.push_back(path);
    newenv_.push_back(lang);
    if (term) {
        newenv_.push_back(term);
    }
    if (ld_library_path) {
        newenv_.push_back(ld_library_path);
    }
    newenv_.push_back(homebuf);
    while (argc > 0) {
        const char* arg = argv[0];
        const char* argpos = arg;
        while (*argpos && (isalnum((unsigned char) *argpos) || *argpos == '_')) {
            ++argpos;
        }
        if (arg == argpos || *argpos != '=') {
            break;
        }
        std::vector<const char*>::size_type i = 0;
        while (i < newenv_.size() && strncmp(newenv_[i], arg, argpos - arg) != 0) {
            ++i;
        }
        if (i < newenv_.size()) {
            newenv_[i] = arg;
        } else {
            newenv_.push_back(arg);
        }
        --argc, ++argv;
    }
    newenv_.push_back(NULL);

    // create command
    if (!argc) {
        die("Nothing to run\n");
    }
    delete[] argv_;
    argv_ = new char*[5 + argc];
    if (!argv_) {
        die("Out of memory\n");
    }
    int newargvpos = 0;
    std::string command;
    argv_[newargvpos++] = (char*) owner_sh_.c_str();
    argv_[newargvpos++] = (char*) "-l";
    argv_[newargvpos++] = (char*) "-c";
    if (argc == 1) {
        command = argv[0];
    } else {
        command = shell_quote(argv[0]);
        for (int i = 0; i < argc; ++i) {
            command += std::string(" ") + shell_quote(argv[i]);
        }
    }
    argv_[newargvpos++] = const_cast<char*>(command.c_str());
    argv_[newargvpos++] = nullptr;

    // store other arguments
    this->jaildir_ = &jaildir;
    this->inputfd_ = inputfd;
    gettimeofday(&this->start_time_, nullptr);
    if (timeout > 0) {
        this->expiry_ = timer_add_delay(this->start_time_, timeout);
    } else {
        timerclear(&this->expiry_);
    }
    this->idle_timeout_ = idle_timeout;
    if (idle_timeout > 0) {
        this->active_time_ = this->start_time_;
        this->idle_expiry_ = timer_add_delay(this->active_time_, idle_timeout);
    }

    // enter the jail
#if __linux__
    char* new_stack = (char*) malloc(256 * 1024);
    if (!new_stack) {
        die("Out of memory\n");
    }
    if (verbose) {
        fprintf(verbosefile, "-clone-\n");
    }
    int child;
    if (!dryrun) {
        child = clone(exec_clone_function, new_stack + 256 * 1024,
                      CLONE_NEWIPC | CLONE_NEWNS | CLONE_NEWPID | SIGCHLD, this);
    } else {
        exec_clone_function(this);
        exit(0);
    }
    if (child == -1) {
        perror_die("clone");
    }
#else
    int child = fork();
    if (child == 0) {
        exit(exec_go());
    }
#endif
    if (child == -1) {
        perror_die("fork");
    }
    write_pid(child);

    // we don't need file descriptors any more
    close(STDIN_FILENO);
    close(STDOUT_FILENO);
    close(STDERR_FILENO);

    int exit_status = 0;
    if (foreground) {
        int r = setresgid(caller_group, caller_group, caller_group);
        (void) r;
        r = setresuid(caller_owner, caller_owner, caller_owner);
        (void) r;
        exit_status = x_waitpid(child, 0).second;
    } else {
        pidfd = -1;
    }
    exit(exit_status);
}

int jailownerinfo::exec_go() {
#if __linux__
    mount_status = 2;

    // ensure we truly have a private mount namespace: no shared mounts
    // (some Linux distros, such as Ubuntu 15.10, have / a shared mount
    // by default, which means mount changes propagate despite CLONE_NEWNS.
    // This undoes the shared mount)
    if (verbose) {
        fprintf(verbosefile, "mount --make-rslave /\n");
    }
    if (mount("none", "/", NULL, MS_REC | MS_SLAVE, NULL) != 0) {
        perror_die("mount --make-rslave /");
    }

    populate_mount_table();     // ensure we know how to mount /proc
    for (size_t i = 0; i != delayed_mounts.size(); i += 2) {
        handle_mount(delayed_mounts[i], delayed_mounts[i+1], true);
    }
    handle_mount("/proc", jaildir_->dir + "proc", true);
    handle_mount("/dev/pts", jaildir_->dir + "dev/pts", true);
    handle_mount("/tmp", jaildir_->dir + "tmp", true);
    handle_mount("/run", jaildir_->dir + "run", true);
#endif

    // chroot, remount /proc
    if (verbose) {
        fprintf(verbosefile, "cd %s\n", jaildir_->dir.c_str());
    }
    if (!dryrun && chdir(jaildir_->dir.c_str()) != 0) {
        perror_die(jaildir_->dir);
    }
    if (verbose) {
        fprintf(verbosefile, "chroot .\n");
    }
    if (!dryrun && chroot(".") != 0) {
        perror_die("chroot");
    }

    // create a pty
    int ptymaster = -1;
    char* ptyslavename = NULL;
    if (verbose) {
        fprintf(verbosefile, "su %s\nmake-pty\n", uid_to_name(owner_));
    }
    if (!dryrun) {
        // change effective uid/gid, but save root for later
        if (setresgid(group_, group_, ROOT) != 0) {
            perror_die("setresgid");
        }
        if (setresuid(owner_, owner_, ROOT) != 0) {
            perror_die("setresuid");
        }
        // create pty
        if ((ptymaster = posix_openpt(O_RDWR)) == -1) {
            perror_die("posix_openpt");
        }
        if (grantpt(ptymaster) == -1) {
            perror_die("grantpt");
        }
        if (unlockpt(ptymaster) == -1) {
            perror_die("unlockpt");
        }
        if ((ptyslavename = ptsname(ptymaster)) == NULL) {
            perror_die("ptsname");
        }
    }

    // change into their home directory
    if (verbose) {
        fprintf(verbosefile, "cd %s\n", owner_home_.c_str());
    }
    if (!dryrun && chdir(owner_home_.c_str()) != 0) {
        perror_die(owner_home_);
    }

    // check that shell exists
    if (!dryrun && access(owner_sh_.c_str(), R_OK | X_OK) != 0) {
        perror_die(owner_sh_);
    }

    if (verbose) {
        for (int i = 0; newenv_[i]; ++i) {
            fprintf(verbosefile, "%s ", newenv_[i]);
        }
        for (int i = 0; argv_[i]; ++i) {
            fprintf(verbosefile, i ? " %s" : "%s", shell_quote(argv_[i]).c_str());
        }
        fprintf(verbosefile, "\n");
    }

    if (!dryrun) {
        start_sigpipe();
        pid_t child = fork();
        if (child < 0) {
            perror_die("fork");
        } else if (child == 0) {
            child = getpid();
#if __linux__
            // sigfd is close-on-exec
#else
            close(sigpipe[0]);
            close(sigpipe[1]);
#endif

            // reduce privileges permanently
            if (setresgid(group_, group_, group_) != 0) {
                perror_die("setresgid");
            }
            if (setresuid(owner_, owner_, owner_) != 0) {
                perror_die("setresuid");
            }
            if (setsid() == -1) {
                perror_die("setsid");
            }

            int ptyslave = open(ptyslavename, O_RDWR);
            if (ptyslave == -1) {
                perror_die(ptyslavename);
            }
            close(ptymaster);
#ifdef TIOCSCTTY
            ioctl(ptyslave, TIOCSCTTY, 0);
#endif
            tcsetpgrp(ptyslave, child);
#ifdef TIOCGWINSZ
            {
                struct winsize ws;
                ioctl(ptyslave, TIOCGWINSZ, &ws);
                ws.ws_row = 25;
                ws.ws_col = 80;
                ioctl(ptyslave, TIOCSWINSZ, &ws);
            }
#endif
            if (no_onlcr) {
                struct termios tty;
                if (tcgetattr(ptyslave, &tty) >= 0) {
                    tty.c_oflag &= ~ONLCR;
                    tcsetattr(ptyslave, TCSANOW, &tty);
                }
            }

            if (inputfd_ > 0 || stdin_tty_) {
                dup2(ptyslave, STDIN_FILENO);
            }
            if (inputfd_ > 0 || stdout_tty_) {
                dup2(ptyslave, STDOUT_FILENO);
            }
            if (inputfd_ > 0 || stderr_tty_) {
                dup2(ptyslave, STDERR_FILENO);
            }
            close(ptyslave);

            // restore all signals to their default actions
            // (e.g., PHP may have ignored SIGPIPE; don't want that
            // to propagate to student code!)
            for (int sig = 1; sig < NSIG; ++sig) {
                signal(sig, SIG_DFL);
            }

            if (execve(argv_[0], (char* const*) argv_,
                       (char* const*) newenv_.data()) != 0) {
                fprintf(stderr, "exec %s: %s\n", owner_sh_.c_str(), strerror(errno));
                exit(126);
            }
        } else {
            wait_background(child, ptymaster);
        }
    }

    return 0;
}

extern "C" {
#if !__linux__
void sighandler(int signo) {
    if (signo == SIGTERM) {
        got_sigterm = 1;
    }
    char c = (char) signo;
    ssize_t w = write(sigpipe[1], &c, 1);
    (void) w;
}
#endif
}

static void make_nonblocking(int fd) {
    fcntl(fd, F_SETFL, fcntl(fd, F_GETFL, 0) | O_NONBLOCK);
}

void jailownerinfo::start_sigpipe() {
#if __linux__
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGCHLD);
    sigaddset(&mask, SIGTERM);
    if (sigprocmask(SIG_BLOCK, &mask, NULL) == -1) {
        perror_die("sigprocmask");
    }
    sigfd = signalfd(-1, &mask, SFD_NONBLOCK | SFD_CLOEXEC);
    if (sigfd == -1) {
        perror_die("signalfd");
    }
#else
    int r = pipe(sigpipe);
    if (r != 0) {
        perror_die("pipe");
    }
    make_nonblocking(sigpipe[0]);
    make_nonblocking(sigpipe[1]);

    struct sigaction sa;
    sa.sa_handler = sighandler;
    sigemptyset(&sa.sa_mask);
    sa.sa_flags = 0;
    sigaction(SIGCHLD, &sa, NULL);
    sigaction(SIGTERM, &sa, NULL);
#endif

    if (inputfd_ > 0 || stdin_tty_) {
        make_nonblocking(inputfd_);
    }
    if (inputfd_ > 0 || stdout_tty_) {
        make_nonblocking(STDOUT_FILENO);
    }
}

bool jailownerinfo::buffer::transfer_in(int from) {
    bool any = false;
    if (end_ == cap_ && head_ != 0) {
        memmove(buf_, &buf_[head_], end_ - head_);
        tail_ -= head_;
        end_ -= head_;
        head_ = 0;
    }

    if (from >= 0 && !input_closed_ && end_ != cap_) {
        ssize_t nr = read(from, &buf_[end_], cap_ - end_);
        if (nr != 0 && nr != -1) {
            end_ += nr;
            any = true;
        } else if (nr == 0) {
            input_closed_ = true;
        } else if (nr == -1 && errno != EINTR && errno != EAGAIN) {
            input_closed_ = true;
            rerrno_ = errno;
        }
        // end_ allows us to rewrite the buffer, but that
        // functionality is currently unused
        tail_ = end_;
    }
    return any;
}

bool jailownerinfo::buffer::transfer_out(int to) {
    bool any = false;
    if (to >= 0 && !output_closed_ && head_ != tail_) {
        ssize_t nw = write(to, &buf_[head_], tail_ - head_);
        if (nw != 0 && nw != -1) {
            head_ += nw;
            any = true;
        } else if (errno != EINTR && errno != EAGAIN) {
            output_closed_ = true;
        }
    }
    return any;
}

bool jailownerinfo::buffer::done() {
    return input_closed_ && head_ == tail_;
}

void jailownerinfo::block(int ptymaster) {
    struct pollfd p[4];

#if __linux__
    p[0].fd = sigfd;
#else
    p[0].fd = sigpipe[0];
#endif
    p[0].events = POLLIN;
    int nfd = 1;

    if (!to_slave_.input_closed_ && !to_slave_.output_closed_) {
        p[nfd].fd = inputfd_;
        p[nfd].events = POLLIN;
        ++nfd;
    }

    int ptymaster_events = 0;
    if (!from_slave_.input_closed_ && !from_slave_.output_closed_) {
        ptymaster_events |= POLLIN;
    }
    if (!to_slave_.output_closed_ && to_slave_.head_ != to_slave_.tail_) {
        ptymaster_events |= POLLOUT;
    }
    if (ptymaster_events) {
        p[nfd].fd = ptymaster;
        p[nfd].events = ptymaster_events;
        ++nfd;
    }

    if (!from_slave_.output_closed_ && from_slave_.head_ != from_slave_.tail_) {
        p[nfd].fd = STDOUT_FILENO;
        p[nfd].events = POLLOUT;
        ++nfd;
    }

    int timeout_ms = 3600000;
    struct timeval now;
    if (timerisset(&expiry_) || idle_timeout_ > 0) {
        gettimeofday(&now, nullptr);
    }
    if (timerisset(&expiry_)) {
        if (timercmp(&now, &expiry_, <)) {
            timeout_ms = std::min(timeout_ms, timer_difference_ms(expiry_, now));
        } else {
            timeout_ms = 0;
        }
    }
    if (timerisset(&idle_expiry_)) {
        if (timercmp(&now, &idle_expiry_, <)) {
            timeout_ms = std::min(timeout_ms, timer_difference_ms(idle_expiry_, now));
        } else {
            timeout_ms = 0;
        }
    }

    if (poll(p, nfd, 0) == 0) {
        has_blocked_ = true;
        poll(p, nfd, timeout_ms);
    }

    if (p[0].revents & POLLIN) {
#if __linux__
        struct signalfd_siginfo ssi;
        ssize_t r;
        while ((r = read(sigfd, &ssi, sizeof(ssi))) == sizeof(ssi)) {
            if (ssi.ssi_signo == SIGTERM) {
                got_sigterm = 1;
            }
        }
        assert(r == 0 || (r == -1 && errno == EAGAIN));
#else
        char buf[128];
        while (read(sigpipe[0], buf, sizeof(buf)) > 0) {
            /* skip */
        }
#endif
    }
}

int jailownerinfo::check_child_timeout(pid_t child, bool waitpid) {
    std::pair<pid_t, int> xr;
    do {
        xr = x_waitpid(-1, WNOHANG);
        if (xr.first == child) {
            child_status_ = xr.second;
        }
    } while (xr.first != -1);

    if (errno != EAGAIN && errno != ECHILD) {
        return 125;
    } else if (child_status_ >= 0 && waitpid) {
        return child_status_;
    } else if (got_sigterm) {
        return 128 + SIGTERM;
    } else {
        struct timeval now;
        if ((timerisset(&expiry_) || timerisset(&idle_expiry_))
            && gettimeofday(&now, nullptr) == 0) {
            if ((timerisset(&expiry_) && timercmp(&now, &expiry_, >))
                || (timerisset(&idle_expiry_) && timercmp(&now, &idle_expiry_, >))) {
                return 124;
            }
        }
        errno = EAGAIN;
        return -1;
    }
}

void jailownerinfo::write_timing() {
    off_t out_offset = lseek(STDOUT_FILENO, 0, SEEK_CUR);
    struct timeval now, delta;
    gettimeofday(&now, nullptr);
    timersub(&now, &this->start_time_, &delta);
    unsigned long long deltamsecs = (delta.tv_sec * 1000000 + delta.tv_usec) / 1000;
    char timingstr[256];
    int written = 0;
    int len = snprintf(timingstr, sizeof(timingstr), "%llu,%llu\n", deltamsecs, (unsigned long long) out_offset);
    assert(len < 256);
    while (written < len) {
        int r = write(timingfd, timingstr, len);
        if (r < 0) {
            perror_die("Timing file");
        }
        written += r;
    }
}

void jailownerinfo::wait_background(pid_t child, int ptymaster) {
    // This process is the `init` (pid 1) of the new process namespace.
    // On Linux, if it dies, everything in the jail dies too.

    // go back to being the caller
    if (setresuid(ROOT, ROOT, ROOT) != 0
        || setresgid(caller_group, caller_group, caller_group) != 0
        || setresuid(caller_owner, caller_owner, caller_owner) != 0) {
        perror("setresuid");
        exec_done(child, 127);
    }

    // if input is a tty, put it in raw mode with short blocking
    if (ttyfd_ >= 0) {
        struct termios tty = ttyfd_termios_;
        cfmakeraw(&tty);
        tty.c_cc[VMIN] = 1;
        tty.c_cc[VTIME] = 1;
        (void) tcsetattr(ttyfd_, TCSANOW, &tty);
    }

    make_nonblocking(ptymaster);
    fflush(stdout);
    if (inputfd_ == 0 && !stdin_tty_) {
        close(STDIN_FILENO);
        to_slave_.input_closed_ = to_slave_.output_closed_ = true;
    }
    if (inputfd_ == 0 && !stdout_tty_ && !stderr_tty_) {
        close(STDOUT_FILENO);
        from_slave_.input_closed_ = from_slave_.output_closed_ = true;
        from_slave_.rerrno_ = EIO; // don't misinterpret closed as error
    }

    while (true) {
        // check child and timeout
        // (only wait for child if read done/failed)
        int exit_status = check_child_timeout(child, from_slave_.done());
        if (exit_status != -1) {
            exec_done(child, exit_status);
        }

        // if child has not died, and read produced error, report it
        if (from_slave_.input_closed_ && from_slave_.rerrno_ != EIO) {
            fprintf(stderr, "read: %s%s", strerror(from_slave_.rerrno_), no_onlcr ? "\n" : "\r\n");
            exec_done(child, 125);
        }

        // wait for something to occur
        block(ptymaster);
        bool any = false;

        // transfer data
        any = to_slave_.transfer_in(inputfd_) || any;
        if (to_slave_.head_ != to_slave_.tail_
            && memmem(&to_slave_.buf_[to_slave_.head_], to_slave_.tail_ - to_slave_.head_, "\x1b\x03", 2) != NULL) {
            exec_done(child, 128 + SIGTERM);
        }
        any = to_slave_.transfer_out(ptymaster) || any;
        any = from_slave_.transfer_in(ptymaster) || any;
        if (has_blocked_ && timingfd != -1) {
            write_timing();
            has_blocked_ = false;
        }
        any = from_slave_.transfer_out(STDOUT_FILENO) || any;

        // maybe reset idle timeout
        if (any && idle_timeout_ > 0) {
            gettimeofday(&active_time_, nullptr);
            idle_expiry_ = timer_add_delay(active_time_, idle_timeout_);
        }
    }
}

void jailownerinfo::exec_done(pid_t child, int exit_status) {
    if (timingfd != -1) {
        write_timing();
    }
    const char* xmsg = nullptr;
    if (exit_status == 124 && !quiet) {
        xmsg = "...timed out";
    } else if (exit_status == 128 + SIGTERM && !quiet) {
        xmsg = "...terminated";
    }
    if (xmsg) {
        const char* nl = no_onlcr ? "\n" : "\r\n";
        fprintf(stderr, inputfd_ > 0 || stderr_tty_ ? "%s\x1b[3;7;31m%s\x1b[K\x1b[0m%s\x1b[K%s" : "%s%s%s%s", nl, xmsg, nl, nl);
    }
#if __linux__
    (void) child;
#else
    if (exit_status >= 124) {
        kill(child, SIGKILL);
    }
#endif
    fflush(stderr);
    if (ttyfd_ >= 0) {
        (void) tcsetattr(ttyfd_, TCSAFLUSH, &ttyfd_termios_);
    }
    exit(exit_status);
}


static __attribute__((noreturn)) void usage(jailaction action = do_start) {
    if (action == do_start) {
        fprintf(stderr, "Usage: pa-jail add [-nh] [-f FILE | -F DATA] [-S SKELETON] JAILDIR [USER]\n\
       pa-jail run [--fg] [-nqhL] [-T TIMEOUT] [-I TIMEOUT] [-p PIDFILE] \\\n\
                   [-i INPUT] [-f FILE | -F DATA] [-S SKELETON] \\\n\
                   JAILDIR USER COMMAND\n\
       pa-jail mv SOURCE DEST\n\
       pa-jail rm [-nf] JAILDIR\n");
    } else if (action == do_mv) {
        fprintf(stderr, "Usage: pa-jail mv [-n] SOURCE DEST\n\
Safely move a jail from SOURCE to DEST. SOURCE and DEST must be allowed\n\
by /etc/pa-jail.conf.\n\
\n\
  -n, --dry-run     print the actions that would be taken, don't run them\n");
    } else if (action == do_rm) {
        fprintf(stderr, "Usage: pa-jail rm [-nf] JAILDIR\n\
Unmount and remove a jail. Like `rm -r[f] --one-file-system JAILDIR`.\n\
JAILDIR must be allowed by /etc/pa-jail.conf.\n\
\n\
  -f, --force       do not complain if JAILDIR doesn't exist\n\
  -n, --dry-run     print the actions that would be taken, don't run them\n\
  -V, --verbose     print actions as well as running them\n");
    } else {
        if (action == do_add) {
            fprintf(stderr, "Usage: pa-jail add [OPTIONS...] JAILDIR [USER]\n\
Create or augment a jail. JAILDIR must be allowed by /etc/pa-jail.conf.\n\n");
        } else {
            fprintf(stderr, "Usage: pa-jail run [OPTIONS...] JAILDIR USER [NAME=VALUE...] COMMAND...\n\
Run COMMAND as USER in the JAILDIR jail. JAILDIR must be allowed by\n\
/etc/pa-jail.conf.\n\n");
        }
        fprintf(stderr, "  -f, --manifest-file FILE  populate jail with manifest from FILE\n");
        fprintf(stderr, "  -F, --manifest MANIFEST   populate jail with MANIFEST\n");
        fprintf(stderr, "  -h, --chown-home          change ownership of USER homedir\n");
        fprintf(stderr, "  -S, --skeleton SKELDIR    populate jail from SKELDIR\n");
        if (action == do_run) {
            fprintf(stderr, "  -p, --pid-file PIDFILE    write jail process PID to PIDFILE\n\
  -P, --pid-contents STR    write STR to PIDFILE\n\
  -i, --input INPUTSOCKET   use TTY, read input from INPUTSOCKET\n\
      --no-onlcr            don't translate \\n -> \\r\\n in output\n\
  -T, --timeout TIMEOUT     kill the jail after TIMEOUT seconds\n\
  -I, --idle-timeout TIMEOUT  kill the jail after TIMEOUT idle seconds\n\
      --fg                  run in the foreground\n");
        }
        fprintf(stderr, "  -n, --dry-run             print actions, don't run them\n\
  -V, --verbose             print actions and run them\n");
    }
    exit(1);
}

static struct option longoptions_before[] = {
    { "verbose", no_argument, NULL, 'V' },
    { "dry-run", no_argument, NULL, 'n' },
    { "help", no_argument, NULL, 'H' },
    { NULL, 0, NULL, 0 }
};

#define ARG_ONLCR    1000
#define ARG_NO_ONLCR 1001
static struct option longoptions_run[] = {
    { "verbose", no_argument, NULL, 'V' },
    { "dry-run", no_argument, NULL, 'n' },
    { "help", no_argument, NULL, 'H' },
    { "skeleton", required_argument, NULL, 'S' },
    { "pid-file", required_argument, NULL, 'p' },
    { "pid-contents", required_argument, NULL, 'P' },
    { "contents-file", required_argument, NULL, 'f' },
    { "contents", required_argument, NULL, 'F' },
    { "manifest-file", required_argument, NULL, 'f' },
    { "manifest", required_argument, NULL, 'F' },
    { "fg", no_argument, NULL, 'g' },
    { "timeout", required_argument, NULL, 'T' },
    { "idle-timeout", required_argument, NULL, 'I' },
    { "input", required_argument, NULL, 'i' },
    { "chown-home", no_argument, NULL, 'h' },
    { "chown-user", required_argument, NULL, 'u' },
    { "onlcr", no_argument, NULL, ARG_ONLCR },
    { "no-onlcr", no_argument, NULL, ARG_NO_ONLCR },
    { "timing-file", required_argument, NULL, 't' },
    { NULL, 0, NULL, 0 }
};

static struct option longoptions_rm[] = {
    { "verbose", no_argument, NULL, 'V' },
    { "dry-run", no_argument, NULL, 'n' },
    { "help", no_argument, NULL, 'H' },
    { "force", no_argument, NULL, 'f' },
    { NULL, 0, NULL, 0 }
};

static struct option* longoptions_action[] = {
    longoptions_before, longoptions_run, longoptions_run, longoptions_rm, longoptions_before
};
static const char* shortoptions_action[] = {
    "+Vn", "VnS:f:F:p:P:T:I:qi:hu:t:", "VnS:f:F:p:P:T:I:qi:hu:t:", "Vnf", "Vn"
};

int main(int argc, char** argv) {
    // parse arguments
    jailaction action = do_start;
    bool chown_home = false, foreground = false;
    double timeout = -1, idle_timeout = -1;
    std::string inputarg, linkarg, manifest;
    std::vector<std::string> chown_user_args;
    pidcontents = "$$\n";

    int ch;
    while (1) {
        while ((ch = getopt_long(argc, argv, shortoptions_action[(int) action],
                                 longoptions_action[(int) action], NULL)) != -1) {
            if (ch == 'V') {
                verbose = true;
            } else if (ch == 'S') {
                linkarg = optarg;
            } else if (ch == 'n') {
                verbose = dryrun = true;
            } else if (ch == 'f' && action == do_rm) {
                doforce = true;
            } else if (ch == 'f') {
                manifest += file_get_contents(optarg, 2);
                if (!manifest.empty() && manifest.back() != '\n') {
                    manifest.push_back('\n');
                }
            } else if (ch == 'F') {
                manifest += optarg;
                if (!manifest.empty() && manifest.back() != '\n') {
                    manifest.push_back('\n');
                }
            } else if (ch == 'p' && action == do_run) {
                pidfilename = optarg;
            } else if (ch == 'P' && action == do_run) {
                pidcontents = optarg;
            } else if (ch == 'i') {
                inputarg = optarg;
            } else if (ch == ARG_ONLCR) {
                no_onlcr = false;
            } else if (ch == ARG_NO_ONLCR) {
                no_onlcr = true;
            } else if (ch == 'g') {
                foreground = true;
            } else if (ch == 'h') {
                chown_home = true;
            } else if (ch == 'q') {
                quiet = true;
            } else if (ch == 'u') {
                chown_user_args.push_back(optarg);
            } else if (ch == 'T') {
                char* end;
                timeout = strtod(optarg, &end);
                if (end == optarg || *end != 0) {
                    usage();
                }
            } else if (ch == 'I') {
                char* end;
                idle_timeout = strtod(optarg, &end);
                if (end == optarg || *end != 0) {
                    usage();
                }
            } else if (ch == 't' && action == do_run) {
                timingfilename = optarg;
            } else { /* if (ch == 'H') */
                usage(action);
            }
        }
        if (action != do_start) {
            break;
        }
        if (optind == argc) {
            usage();
        } else if (strcmp(argv[optind], "rm") == 0) {
            action = do_rm;
        } else if (strcmp(argv[optind], "mv") == 0) {
            action = do_mv;
        } else if (strcmp(argv[optind], "init") == 0
                   || strcmp(argv[optind], "add") == 0) {
            action = do_add;
        } else if (strcmp(argv[optind], "run") == 0) {
            action = do_run;
        } else {
            usage();
        }
        argc -= optind;
        argv += optind;
        optind = 1;
    }

    // check arguments
    if (action == do_run && optind + 2 >= argc) {
        action = do_add;
    }
    if ((action == do_rm && optind + 1 != argc)
        || (action == do_mv && optind + 2 != argc)
        || (action == do_add && optind != argc - 1 && optind + 2 != argc)
        || (action == do_run && optind + 3 > argc)
        || (action == do_rm && (!linkarg.empty() || !manifest.empty() || !inputarg.empty()))
        || (action == do_mv && (!linkarg.empty() || !manifest.empty() || !inputarg.empty()))
        || !argv[optind][0]
        || (action == do_mv && !argv[optind+1][0])) {
        usage();
    }
    if (verbose && !dryrun) {
        verbosefile = stderr;
    }

    // parse user
    jailownerinfo jailuser;
    if ((action == do_add || action == do_run) && optind + 1 < argc) {
        jailuser.init(argv[optind + 1]);
    }

    // open infile non-blocking as current user
    // if it is a named FIFO, open it read-write so we never get EOF
    int inputfd = 0;
    if (!inputarg.empty() && !dryrun) {
        struct stat st;
        int mode = O_RDONLY;
        if (stat(inputarg.c_str(), &st) == 0 && S_ISFIFO(st.st_mode)) {
            mode = O_RDWR;
        }
        inputfd = open(inputarg.c_str(), mode | O_CLOEXEC | O_NONBLOCK);
        if (inputfd == -1) {
            perror_die(inputarg);
        }
    }

    // open pidfile as current user
    if (!pidfilename.empty() && verbose) {
        fprintf(verbosefile, "touch %s\nflock %s\n", pidfilename.c_str(), pidfilename.c_str());
    }
    if (!pidfilename.empty() && !dryrun) {
        pidfd = open(pidfilename.c_str(), O_WRONLY | O_CLOEXEC | O_CREAT | O_TRUNC, 0666);
        if (pidfd == -1) {
            perror_die(pidfilename);
        }
        while (true) {
            int r = flock(pidfd, LOCK_EX);
            if (r == 0) {
                break;
            } else if (r == -1 && errno != EINTR) {
                perror_die(pidfilename);
            }
        }
    }

    // create timing file as current user
    if (!timingfilename.empty() && verbose) {
        fprintf(verbosefile, "touch %s\n", timingfilename.c_str());
    }
    if (!timingfilename.empty() && !dryrun) {
        timingfd = open(timingfilename.c_str(), O_WRONLY | O_CLOEXEC | O_CREAT | O_TRUNC, 0666);
        if (timingfd == -1) {
            perror_die(timingfilename);
        }
    }

    // escalate so that the real (not just effective) UID/GID is root. this is
    // so that the system processes will execute as root
    caller_owner = getuid();
    caller_group = getgid();
    if (!dryrun && setresgid(ROOT, ROOT, ROOT) < 0) {
        perror_die("setresgid");
    }
    if (!dryrun && setresuid(ROOT, ROOT, ROOT) < 0) {
        perror_die("setresuid");
    }

    // check the jail directory
    // - no special characters
    // - path has no symlinks
    // - `/etc/pa-jail.conf` is owned by root, writable only by root
    // - `/etc/pa-jail.conf` enables the jail directory and does not disable
    //   the jail directory
    // - everything above that dir is owned by by root and writable only by
    //   root
    // - stuff below the allowed jail directory dynamically created as
    //   necessary
    // - try to eliminate TOCTTOU
    pajailconf jailconf;
    jaildirinfo jaildir(argv[optind], linkarg, action, jailconf);

    // move the sandbox if asked
    if (action == do_mv) {
        std::string newpath = check_filename(absolute(argv[optind + 1]));
        if (newpath.empty() || newpath[0] != '/') {
            die("%s: Bad characters in move destination\n", argv[optind + 1]);
        }

        // allow second argument to be a directory
        struct stat s;
        if (stat(newpath.c_str(), &s) == 0 && S_ISDIR(s.st_mode)) {
            newpath = path_endslash(newpath) + jaildir.component;
        }

        // check jail allowance
        if (!jailconf.allow_jail(newpath)) {
            die("%s: Destination jail disabled by /etc/pa-jail.conf\n%s",
                newpath.c_str(), jailconf.disable_message().c_str());
        }

        if (verbose) {
            fprintf(verbosefile, "mv %s%s %s\n", jaildir.parent.c_str(), jaildir.component.c_str(), newpath.c_str());
        }
        if (!dryrun && renameat(jaildir.parentfd, jaildir.component.c_str(), jaildir.parentfd, newpath.c_str()) != 0) {
            die("mv %s%s %s: %s\n", jaildir.parent.c_str(), jaildir.component.c_str(), newpath.c_str(), strerror(errno));
        }
        exit(0);
    }

    // kill the sandbox if asked
    if (action == do_rm) {
        // unmount EVERYTHING mounted in the jail!
        // INCLUDING MY HOME DIRECTORY
        jaildir.dir = path_endslash(jaildir.dir);
        populate_mount_table();
        for (auto it = mount_table.begin(); it != mount_table.end(); ++it) {
            if (it->first.length() >= jaildir.dir.length()
                && memcmp(it->first.data(), jaildir.dir.data(),
                          jaildir.dir.length()) == 0)
                handle_umount(it);
        }
        // remove the jail
        jaildir.remove();
        exit(0);
    }

    // check skeleton directory
    if (!jaildir.skeletondir.empty()) {
        if (v_ensuredir(jaildir.skeletondir, 0755, true) < 0) {
            perror_die(jaildir.skeletondir);
        }
        linkdir = path_noendslash(jaildir.skeletondir);
    }

    // create the home directory
    if (!jailuser.owner_home_.empty()) {
        if (v_ensuredir(jaildir.dir + "/home", 0755, true) < 0) {
            perror_die(jaildir.dir + "/home");
        }
        std::string jailhome = jaildir.dir + jailuser.owner_home_;
        int r = v_ensuredir(jailhome, 0700, true);
        uid_t want_owner = action == do_add ? caller_owner : jailuser.owner_;
        gid_t want_group = action == do_add ? caller_group : jailuser.group_;
        if (r < 0
            || (r > 0 && x_lchown(jailhome.c_str(), want_owner, want_group))) {
            perror_die(jailhome);
        }
        // also create in skeleton, but ignore errors
        if (!linkdir.empty()) {
            (void) v_ensuredir(linkdir + "/home", 0755, true);
            std::string linkhome = linkdir + jailuser.owner_home_;
            r = v_ensuredir(linkhome, 0700, true);
            if (r > 0) {
                x_lchown(linkhome.c_str(), jailuser.owner_, jailuser.group_);
            }
        }
    }

    // set ownership
    if (chown_home) {
        jaildir.chown_home();
    }
    for (const auto& f : chown_user_args) {
        if (!jailconf.allow_jail_subdir(f)) {
            die("%s: --chown-user directory disabled by /etc/pa-jail.conf\n%s",
                f.c_str(), jailconf.disable_message().c_str());
        }
        jaildir.chown_recursive(f, jailuser.owner_, jailuser.group_);
    }

    // construct the jail
    mount_status = optind + 2 < argc;
    dstroot = path_noendslash(jaildir.dir);
    assert(dstroot != "/");
    if (!manifest.empty()) {
        mode_t old_umask = umask(0);
        if (construct_jail(jaildir.dev, manifest, false) != 0) {
            exit(1);
        }
        umask(old_umask);
    }

    // close `parentfd`
    close(jaildir.parentfd);
    jaildir.parentfd = -1;

    // maybe execute a command in the jail
    if (optind + 2 < argc) {
        jailuser.exec(argc - (optind + 2), argv + optind + 2, jaildir, inputfd, timeout, idle_timeout, foreground);
    }

    // close timing and lock file if appropriate
    if (timingfd != -1) {
        close(timingfd);
    }

    exit(0);
}
