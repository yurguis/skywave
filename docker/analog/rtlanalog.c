/*
 * rtlanalog -- analog AM and FM from a dongle shared by rtl_tcp.
 *
 * nrsc5 decodes HD Radio and reaches the dongle over the network, which is the only
 * reason Skywave can hear a station from inside a container while the stick is plugged
 * into the machine outside it. rtl_fm demodulates the analog signal but speaks only to a
 * dongle it can open itself, and Docker on a Mac cannot hand it one. This is the missing
 * piece: an rtl_tcp client that demodulates, so analog travels the same path HD Radio
 * already travels.
 *
 *   rtlanalog -H host:port -M mpx -f 93100000        the FM multiplex, 171 kHz
 *   rtlanalog -H host:port -M am  -f 1140000         AM audio, 16 kHz
 *
 * Raw signed 16-bit mono goes to stdout, for ffmpeg to turn into sound.
 *
 * FM is sent out as the multiplex rather than as audio, which looks like the long way
 * round and is not: the multiplex is what both of its readers want. ffmpeg low-passes it
 * to 15 kHz and de-emphasises it for sound; redsea takes the same bytes and reads the RDS
 * subcarrier at 57 kHz for the station's name and the song. Demodulating to audio here
 * would throw the subcarrier away and leave nothing for redsea to read.
 *
 * AM needs the dongle in direct sampling, because the R820T tuner stops around 24 MHz and
 * the broadcast band is far below it. That bypasses the tuner and samples the ADC, which
 * reaches the whole of HF -- on a dongle wired for it, which an RTL-SDR Blog V3 is.
 */

#include <errno.h>
#include <math.h>
#include <netdb.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/socket.h>

/* rtl_tcp takes five bytes: what to set, then the value, most significant byte first. */
enum {
    CMD_FREQUENCY      = 0x01,
    CMD_SAMPLE_RATE    = 0x02,
    CMD_GAIN_MODE      = 0x03,
    CMD_GAIN           = 0x04,
    CMD_PPM            = 0x05,
    CMD_AGC            = 0x08,
    CMD_DIRECT_SAMPLE  = 0x09,
};

/* Rates chosen so every step down is a whole number.
 *
 * FM: 1026000 is 171000 x 6. Thinned by three it leaves 342 kHz, which still holds the
 * whole 200 kHz-wide station; the multiplex comes out of the discriminator there and is
 * thinned by two more to the 171 kHz redsea expects.
 *
 * AM: 1024000 thinned by 64 leaves 16 kHz, and a broadcast channel is 10 kHz wide. */
#define FM_INPUT_RATE   1026000
#define FM_FIRST_STEP   3
#define FM_SECOND_STEP  2
#define FM_OUTPUT_RATE  (FM_INPUT_RATE / FM_FIRST_STEP / FM_SECOND_STEP)

#define AM_INPUT_RATE   1024000
#define AM_STEP         64
#define AM_OUTPUT_RATE  (AM_INPUT_RATE / AM_STEP)

#define MAX_TAPS 129

/* A low-pass that keeps one sample in `step`, which is the only kind used here. Windowed
 * sinc, built at startup rather than carried as a table so the cutoff can be stated in Hz
 * where it is chosen and read back later. */
typedef struct {
    float taps[MAX_TAPS];
    int   count;
    int   step;
    float historyI[MAX_TAPS];
    float historyQ[MAX_TAPS];
    int   position;
    int   sinceLast;
} Decimator;

static void decimatorInit(Decimator *d, int taps, double cutoffHz, double rateHz, int step)
{
    if (taps > MAX_TAPS) taps = MAX_TAPS;
    if ((taps & 1) == 0) taps--;              /* odd, so there is a centre tap */

    d->count = taps;
    d->step = step;
    d->position = 0;
    d->sinceLast = 0;
    memset(d->historyI, 0, sizeof d->historyI);
    memset(d->historyQ, 0, sizeof d->historyQ);

    const double middle = (taps - 1) / 2.0;
    const double omega  = 2.0 * M_PI * cutoffHz / rateHz;
    double total = 0.0;

    for (int i = 0; i < taps; i++) {
        const double x = i - middle;
        const double sinc = (x == 0.0) ? omega : sin(omega * x) / x;
        /* Blackman: the stopband matters more here than a tap or two of sharpness. */
        const double w = 0.42 - 0.5 * cos(2.0 * M_PI * i / (taps - 1))
                              + 0.08 * cos(4.0 * M_PI * i / (taps - 1));
        d->taps[i] = (float) (sinc * w);
        total += d->taps[i];
    }

    for (int i = 0; i < taps; i++) d->taps[i] /= (float) total;
}

/* Feed one sample; returns 1 and fills the outputs on samples that survive thinning. */
static int decimatorFeed(Decimator *d, float i, float q, float *outI, float *outQ)
{
    d->historyI[d->position] = i;
    d->historyQ[d->position] = q;
    d->position = (d->position + 1) % d->count;

    if (++d->sinceLast < d->step) return 0;
    d->sinceLast = 0;

    float sumI = 0.0f, sumQ = 0.0f;
    int at = d->position;

    for (int t = d->count - 1; t >= 0; t--) {
        sumI += d->historyI[at] * d->taps[t];
        sumQ += d->historyQ[at] * d->taps[t];
        at = (at + 1) % d->count;
    }

    *outI = sumI;
    *outQ = sumQ;

    return 1;
}

static int sendCommand(int socketFd, uint8_t command, uint32_t value)
{
    uint8_t message[5] = {
        command,
        (uint8_t) (value >> 24), (uint8_t) (value >> 16),
        (uint8_t) (value >> 8),  (uint8_t) value,
    };

    return send(socketFd, message, sizeof message, 0) == (ssize_t) sizeof message ? 0 : -1;
}

static int connectTo(const char *host, const char *port)
{
    struct addrinfo hints, *found = NULL;
    memset(&hints, 0, sizeof hints);
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;

    const int error = getaddrinfo(host, port, &hints, &found);
    if (error != 0) {
        fprintf(stderr, "rtlanalog: cannot resolve %s:%s (%s)\n", host, port, gai_strerror(error));

        return -1;
    }

    int socketFd = -1;
    for (struct addrinfo *a = found; a != NULL; a = a->ai_next) {
        socketFd = socket(a->ai_family, a->ai_socktype, a->ai_protocol);
        if (socketFd < 0) continue;
        if (connect(socketFd, a->ai_addr, a->ai_addrlen) == 0) break;
        close(socketFd);
        socketFd = -1;
    }

    freeaddrinfo(found);

    if (socketFd < 0) fprintf(stderr, "rtlanalog: cannot reach rtl_tcp at %s:%s\n", host, port);

    return socketFd;
}

static void usage(void)
{
    fprintf(stderr,
        "rtlanalog -- analog AM and FM from a dongle shared by rtl_tcp\n\n"
        "  -H host[:port]   where rtl_tcp is listening (port 1234 when not given)\n"
        "  -M mpx|am        the FM multiplex at %d Hz, or AM audio at %d Hz\n"
        "  -f hertz         the station, in Hz: 93100000, or 1140000 for AM\n"
        "  -g dB            tuner gain; left out, the dongle chooses\n"
        "  -p ppm           the dongle's frequency error\n\n"
        "Signed 16-bit mono goes to stdout.\n",
        FM_OUTPUT_RATE, AM_OUTPUT_RATE);
}

int main(int argc, char **argv)
{
    const char *host = NULL;
    const char *mode = "mpx";
    double frequency = 0.0, gain = 0.0;
    int havegain = 0, ppm = 0, option;

    while ((option = getopt(argc, argv, "H:M:f:g:p:h")) != -1) {
        switch (option) {
            case 'H': host = optarg; break;
            case 'M': mode = optarg; break;
            case 'f': frequency = atof(optarg); break;
            case 'g': gain = atof(optarg); havegain = 1; break;
            case 'p': ppm = atoi(optarg); break;
            default: usage(); return option == 'h' ? 0 : 64;
        }
    }

    if (host == NULL || frequency <= 0.0) {
        usage();

        return 64;
    }

    const int isAm = strcmp(mode, "am") == 0;

    if (!isAm && strcmp(mode, "mpx") != 0) {
        fprintf(stderr, "rtlanalog: -M takes mpx or am, not \"%s\"\n", mode);

        return 64;
    }

    /* The port may be written onto the host, the way nrsc5 takes it. */
    char hostOnly[256];
    const char *port = "1234";
    const char *colon = strrchr(host, ':');

    if (colon != NULL && strchr(host, ':') == colon) {
        const size_t length = (size_t) (colon - host);
        if (length >= sizeof hostOnly) return 64;
        memcpy(hostOnly, host, length);
        hostOnly[length] = '\0';
        port = colon + 1;
        host = hostOnly;
    }

    const int socketFd = connectTo(host, port);
    if (socketFd < 0) return 1;

    /* rtl_tcp opens with twelve bytes naming itself and the tuner. Nothing here needs
     * them, but they are not samples and must not be read as though they were. */
    uint8_t greeting[12];
    for (size_t got = 0; got < sizeof greeting; ) {
        const ssize_t n = recv(socketFd, greeting + got, sizeof greeting - got, 0);
        if (n <= 0) {
            fprintf(stderr, "rtlanalog: rtl_tcp closed before it said hello\n");

            return 1;
        }
        got += (size_t) n;
    }

    const int inputRate = isAm ? AM_INPUT_RATE : FM_INPUT_RATE;

    /* Order matters: direct sampling changes what tuning means, so it is set first. AM
     * lives below the tuner's range and is reached by sampling the ADC itself, branch 2,
     * which is the one a V3 wires to its antenna. */
    if (sendCommand(socketFd, CMD_DIRECT_SAMPLE, isAm ? 2 : 0) != 0
        || sendCommand(socketFd, CMD_SAMPLE_RATE, (uint32_t) inputRate) != 0
        || sendCommand(socketFd, CMD_AGC, 0) != 0
        || sendCommand(socketFd, CMD_PPM, (uint32_t) ppm) != 0
        || sendCommand(socketFd, CMD_GAIN_MODE, havegain ? 1 : 0) != 0
        || (havegain && sendCommand(socketFd, CMD_GAIN, (uint32_t) lround(gain * 10.0)) != 0)
        || sendCommand(socketFd, CMD_FREQUENCY, (uint32_t) llround(frequency)) != 0) {
        fprintf(stderr, "rtlanalog: rtl_tcp would not take its settings\n");

        return 1;
    }

    Decimator first, second;
    float lastI = 0.0f, lastQ = 0.0f;   /* FM: the previous sample, for the phase step */
    float carrier = 0.0f;               /* AM: the running average that is the carrier */

    if (isAm) {
        /* A channel is 10 kHz wide, so 5 kHz either side of the carrier is all of it. */
        decimatorInit(&first, 127, 5000.0, AM_INPUT_RATE, AM_STEP);
    } else {
        decimatorInit(&first, 63, 110000.0, FM_INPUT_RATE, FM_FIRST_STEP);
        /* Above the 57 kHz subcarrier and below half of 171 kHz: keeps RDS, stops the fold. */
        decimatorInit(&second, 63, 80000.0, FM_INPUT_RATE / FM_FIRST_STEP, FM_SECOND_STEP);
    }

    uint8_t raw[65536];
    int16_t out[8192];
    size_t pending = 0;

    for (;;) {
        const ssize_t got = recv(socketFd, raw + pending, sizeof raw - pending, 0);

        if (got == 0) break;
        if (got < 0) {
            if (errno == EINTR) continue;
            break;
        }

        pending += (size_t) got;

        const size_t pairs = pending / 2;
        size_t ready = 0;

        for (size_t p = 0; p < pairs; p++) {
            /* The dongle sends unsigned bytes around a midpoint of 127.4. */
            const float i = ((float) raw[p * 2]     - 127.4f) / 127.4f;
            const float q = ((float) raw[p * 2 + 1] - 127.4f) / 127.4f;

            float downI, downQ;
            if (!decimatorFeed(&first, i, q, &downI, &downQ)) continue;

            float value;

            if (isAm) {
                /* Envelope: how far the sample is from the origin. The carrier itself is
                 * steady and would be a loud nothing, so it is tracked and taken away.
                 *
                 * Divided by the carrier rather than simply scaled, which makes the output
                 * the depth of the modulation instead of the strength of the signal: a
                 * distant station comes out as loud as a local one, and nothing here has to
                 * know how strong either is. A fixed multiplier was tried first and gave a
                 * station that was mostly silence with its peaks flattened off.
                 *
                 * Then about two thirds, because broadcast AM is allowed past full
                 * modulation on peaks and the rest is headroom for it. */
                const float magnitude = sqrtf(downI * downI + downQ * downQ);
                carrier += (magnitude - carrier) * 0.0005f;

                const float depth = carrier > 1e-6f ? (magnitude - carrier) / carrier : 0.0f;
                value = depth * 0.7f;
            } else {
                float mpxI, mpxQ;
                if (!decimatorFeed(&second, downI, downQ, &mpxI, &mpxQ)) continue;

                /* The signal is in how fast the phase turns, which is the angle between
                 * this sample and the one before it. */
                const float realPart = mpxI * lastI + mpxQ * lastQ;
                const float imagPart = mpxQ * lastI - mpxI * lastQ;
                lastI = mpxI;
                lastQ = mpxQ;
                value = atan2f(imagPart, realPart) * (1.0f / (float) M_PI);
            }

            if (value > 1.0f) value = 1.0f;
            if (value < -1.0f) value = -1.0f;

            out[ready++] = (int16_t) lrintf(value * 32000.0f);

            if (ready == sizeof out / sizeof out[0]) {
                if (fwrite(out, sizeof out[0], ready, stdout) != ready) return 1;
                ready = 0;
            }
        }

        if (ready > 0 && fwrite(out, sizeof out[0], ready, stdout) != ready) return 1;
        fflush(stdout);

        /* An odd trailing byte is half a pair; keep it for the next read. */
        if (pending & 1) raw[0] = raw[pending - 1];
        pending &= 1;
    }

    close(socketFd);

    return 0;
}
