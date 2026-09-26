FROM tobyxdd/hysteria:2.6.5
RUN apk add --no-cache openssh jq \
    && mkdir -p /root/.ssh
ENV ENV="/root/.ashrc"
ENTRYPOINT []