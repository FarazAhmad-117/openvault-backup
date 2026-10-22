export MSYS_NO_PATHCONV=1
N=${OVB_DEMO:-ovb-w3r}
S="$(cd "$(dirname "$0")" && pwd -W 2>/dev/null || pwd)"
docker network create $N-net
docker run -d --name $N-db --network $N-net --network-alias db -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp mysql:8.0
docker run -d --name $N-s3 --network $N-net --network-alias s3srv -e S3_CONFIG='{"identities":[{"name":"ovb","credentials":[{"accessKey":"demoaccess","secretKey":"demosecret0123456789"}],"actions":["Admin","Read","Write","List","Tagging"]}]}' --entrypoint sh chrislusf/seaweedfs:3.97 -c 'printf "%s" "$S3_CONFIG" > /tmp/s3.json && exec weed server -dir=/data -s3 -s3.config=/tmp/s3.json -master.volumeSizeLimitMB=128 -ip=s3srv'
docker run -d --name $N-web --network $N-net --network-alias example.test -v "$S/../../../dist:/zips:ro" -v "$S:/w3:ro" ovb-matrix-php8.2 sleep infinity
docker exec $N-web bash /w3/demo-site-setup.sh > "$S/up.log" 2>&1
docker exec -d $N-web apache2-foreground
echo UPDONE >> "$S/up.log"
