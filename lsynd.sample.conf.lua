-- Sample final lsyncd output

settings = {
    logfile = "/tmp/lsyncd.log",
    statusFile = "/tmp/lsyncd.status",
    nodaemon = false
}

sync {
    default.rsync,
    delay = 0,
    source="/home/ubuntu/test", 
    target="ubuntu@EC2-PRIVATE-IP:/home/ubuntu/test",
    rsyncOps={"-e", "ssh -i /path/to/private-key.pem -o StrictHostKeyChecking=no"}
}
