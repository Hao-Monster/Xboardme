#define _POSIX_C_SOURCE 200809L
#include <errno.h>
#include <fcntl.h>
#include <poll.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <unistd.h>

/* Synthetic CI-only service: no production configuration or application data. */
static volatile sig_atomic_t stopping = 0;
static const char record[] = "images-only-synthetic-persistent-record\n";

static void stop_service(int sig) { (void)sig; stopping = 1; }

static int ensure_record(const char *path) {
    int fd = open(path, O_WRONLY | O_CREAT | O_EXCL, 0644);
    if (fd < 0) return errno == EEXIST ? 0 : 1;
    ssize_t count = write(fd, record, sizeof(record) - 1);
    int synced = fsync(fd);
    int closed = close(fd);
    return count != (ssize_t)(sizeof(record) - 1) || synced != 0 || closed != 0;
}

static int read_record(const char *path) {
    char buffer[sizeof(record) + 1];
    int fd = open(path, O_RDONLY);
    if (fd < 0) return 1;
    ssize_t count = read(fd, buffer, sizeof(buffer));
    int closed = close(fd);
    if (count != (ssize_t)(sizeof(record) - 1) || closed != 0 ||
        memcmp(buffer, record, sizeof(record) - 1) != 0) return 1;
    return fwrite(buffer, 1, (size_t)count, stdout) != (size_t)count;
}

int main(int argc, char **argv) {
    if (argc == 2 && strcmp(argv[1], "--read-sentinels") == 0)
        return read_record("/data/record") || read_record("/redis/record");
    if (argc != 1 || ensure_record("/data/record") || ensure_record("/redis/record"))
        return 2;
    struct sigaction action;
    memset(&action, 0, sizeof(action));
    action.sa_handler = stop_service;
    sigemptyset(&action.sa_mask);
    if (sigaction(SIGTERM, &action, NULL) || sigaction(SIGINT, &action, NULL)) return 3;
    signal(SIGPIPE, SIG_IGN);
    int listener = socket(AF_INET, SOCK_STREAM, 0);
    if (listener < 0) return 4;
    int enabled = 1;
    if (setsockopt(listener, SOL_SOCKET, SO_REUSEADDR, &enabled, sizeof(enabled)) < 0)
        return 5;
    struct sockaddr_in address;
    memset(&address, 0, sizeof(address));
    address.sin_family = AF_INET;
    address.sin_addr.s_addr = htonl(INADDR_ANY);
    address.sin_port = htons(8080);
    if (bind(listener, (struct sockaddr *)&address, sizeof(address)) || listen(listener, 32))
        return 6;
    struct pollfd clients[33];
    char requests[33][1024];
    size_t used[33];
    memset(clients, 0, sizeof(clients));
    memset(used, 0, sizeof(used));
    clients[0].fd = listener;
    clients[0].events = POLLIN;
    for (int index = 1; index < 33; index++) clients[index].fd = -1;
    while (!stopping) {
        int ready = poll(clients, 33, 200);
        if (ready < 0) { if (errno == EINTR) continue; return 7; }
        if (clients[0].revents & POLLIN) {
            int connection = accept(listener, NULL, NULL);
            if (connection < 0) { if (errno == EINTR) continue; return 8; }
            int assigned = 0;
            for (int index = 1; index < 33; index++) {
                if (clients[index].fd >= 0) continue;
                clients[index].fd = connection;
                clients[index].events = POLLIN;
                clients[index].revents = 0;
                used[index] = 0;
                assigned = 1;
                break;
            }
            if (!assigned) close(connection);
        }
        for (int index = 1; index < 33; index++) {
            if (clients[index].fd < 0 || !clients[index].revents) continue;
            ssize_t count = recv(clients[index].fd, requests[index] + used[index],
                                 sizeof(requests[index]) - 1 - used[index], 0);
            if (count > 0) used[index] += (size_t)count;
            requests[index][used[index]] = '\0';
            if (count > 0 && used[index] < 4) continue;
            int keep = 0;
            if (count > 0 && used[index] == 4 && memcmp(requests[index], "PING", 4) == 0) {
                keep = send(clients[index].fd, "PONG", 4, 0) == 4;
                used[index] = 0;
            } else if (count > 0 && memcmp(requests[index], "GET ", 4) == 0) {
                if (strstr(requests[index], "\r\n\r\n") == NULL &&
                    used[index] < sizeof(requests[index]) - 1) continue;
                static const char response[] =
                    "HTTP/1.1 200 OK\r\nContent-Length: 22\r\nConnection: close\r\n"
                    "Content-Type: text/plain\r\n\r\nsynthetic-service-ok\r\n";
                (void)send(clients[index].fd, response, sizeof(response) - 1, 0);
            }
            if (!keep) { close(clients[index].fd); clients[index].fd = -1; }
        }
    }
    for (int index = 0; index < 33; index++)
        if (clients[index].fd >= 0) close(clients[index].fd);
    return 0;
}
