#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=/opt/xboard-bookstack
ENV_FILE="$ROOT/.env"
BOOKSTACK_BASE_URL=https://docs.thinderbox.com

BOOKSTACK_CONTAINER=$(docker ps -q --filter label=com.docker.compose.project=xboard-bookstack --filter label=com.docker.compose.service=bookstack | head -n 1)
XBOARD_CONTAINER=$(docker ps -q --filter label=com.docker.compose.service=xboard | head -n 1)
if [[ -z "$BOOKSTACK_CONTAINER" || -z "$XBOARD_CONTAINER" ]]; then
  echo 'BookStack or Xboard container was not found.' >&2
  exit 1
fi

admin_record=$(docker exec "$XBOARD_CONTAINER" php /www/artisan tinker --execute='$u=App\Models\User::query()->where("is_admin",1)->orderBy("id")->firstOrFail(); echo base64_encode($u->email)." ".base64_encode($u->password).PHP_EOL;' | tail -n 1)
IFS=' ' read -r admin_email_b64 admin_hash_b64 <<< "$admin_record"
admin_email=$(printf '%s' "$admin_email_b64" | base64 -d)
admin_hash=$(printf '%s' "$admin_hash_b64" | base64 -d)
if [[ -z "$admin_email" || ! "$admin_hash" =~ ^\$(2[aby]|argon2)\$ ]]; then
  echo 'The Xboard administrator password hash cannot be safely reused by BookStack.' >&2
  exit 1
fi

docker exec -e XBOARD_ADMIN_EMAIL="$admin_email" -e XBOARD_ADMIN_HASH="$admin_hash" "$BOOKSTACK_CONTAINER" php /app/www/artisan tinker --execute='$u=BookStack\Users\Models\User::query()->where("email","admin@admin.com")->first(); if(!$u){$u=BookStack\Users\Models\User::query()->whereHas("roles",fn($q)=>$q->where("system_name","admin"))->firstOrFail();} Illuminate\Support\Facades\DB::table("users")->where("id",$u->id)->update(["email"=>env("XBOARD_ADMIN_EMAIL"),"name"=>"Xboard Administrator","password"=>env("XBOARD_ADMIN_HASH"),"email_confirmed"=>true,"updated_at"=>now()]);'

read_env() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1; }
write_env() {
  local key=$1 value=$2 tmp
  tmp=$(mktemp)
  awk -F= -v key="$key" '$1 != key {print}' "$ENV_FILE" > "$tmp"
  printf '%s=%s\n' "$key" "$value" >> "$tmp"
  cat "$tmp" > "$ENV_FILE"
  rm -f "$tmp"
  chmod 600 "$ENV_FILE"
}

token_id=$(read_env BOOKSTACK_TOKEN_ID)
token_secret=$(read_env BOOKSTACK_TOKEN_SECRET)
if [[ -z "$token_id" || -z "$token_secret" ]]; then
  token_id=$(openssl rand -hex 16)
  token_secret=$(openssl rand -hex 24)
  write_env BOOKSTACK_TOKEN_ID "$token_id"
  write_env BOOKSTACK_TOKEN_SECRET "$token_secret"
fi

docker exec -e BOOKSTACK_TOKEN_ID="$token_id" -e BOOKSTACK_TOKEN_SECRET="$token_secret" -e BOOKSTACK_ADMIN_EMAIL="$admin_email" "$BOOKSTACK_CONTAINER" php /app/www/artisan tinker --execute='$u=BookStack\Users\Models\User::query()->where("email",env("BOOKSTACK_ADMIN_EMAIL"))->firstOrFail(); $t=BookStack\Api\ApiToken::query()->where("token_id",env("BOOKSTACK_TOKEN_ID"))->first(); if(!$t){$t=(new BookStack\Api\ApiToken())->forceFill(["name"=>"Xboard Knowledge Bridge","token_id"=>env("BOOKSTACK_TOKEN_ID"),"secret"=>Illuminate\Support\Facades\Hash::make(env("BOOKSTACK_TOKEN_SECRET")),"user_id"=>$u->id,"expires_at"=>now()->addYears(10)->format("Y-m-d")]); $t->save();}'

auth_header="Authorization: Token $token_id:$token_secret"
books_json=$(curl -fsS --max-time 15 -H "$auth_header" -H 'Accept: application/json' 'http://127.0.0.1:6875/api/books?count=100')
book_id=$(printf '%s' "$books_json" | docker exec -i "$BOOKSTACK_CONTAINER" php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["data"]??[]) as $book){if(($book["name"]??"")==="Xboard Knowledge Base"){echo (int)$book["id"]; break;}}')
if [[ -z "$book_id" ]]; then
  created=$(curl -fsS --max-time 15 -X POST -H "$auth_header" -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{"name":"Xboard Knowledge Base","description":"Knowledge articles managed from Xboard."}' 'http://127.0.0.1:6875/api/books')
  book_id=$(printf '%s' "$created" | docker exec -i "$BOOKSTACK_CONTAINER" php -r '$j=json_decode(stream_get_contents(STDIN),true); echo (int)($j["id"]??0);')
fi
if [[ ! "$book_id" =~ ^[1-9][0-9]*$ ]]; then
  echo 'BookStack knowledge book could not be created.' >&2
  exit 1
fi
write_env BOOKSTACK_BOOK_ID "$book_id"

docker exec -e BOOKSTACK_BASE_URL="$BOOKSTACK_BASE_URL" -e BOOKSTACK_TOKEN_ID="$token_id" -e BOOKSTACK_TOKEN_SECRET="$token_secret" -e BOOKSTACK_BOOK_ID="$book_id" "$XBOARD_CONTAINER" php -r '$path="/www/.env"; $content=file_exists($path)?file_get_contents($path):""; foreach(["BOOKSTACK_BASE_URL","BOOKSTACK_TOKEN_ID","BOOKSTACK_TOKEN_SECRET","BOOKSTACK_BOOK_ID"] as $key){$value=getenv($key); $line=$key."=".$value; $pattern="/^".preg_quote($key,"/")."=.*$/m"; $content=preg_match($pattern,$content)?preg_replace($pattern,$line,$content):rtrim($content).PHP_EOL.$line.PHP_EOL;} if(file_put_contents($path,$content)===false){fwrite(STDERR,"Unable to update Xboard environment file.\n"); exit(1);}'
docker exec "$XBOARD_CONTAINER" php /www/artisan optimize:clear >/dev/null
docker exec "$XBOARD_CONTAINER" php /www/artisan tinker --execute='if(!app(App\Services\BookStackService::class)->configured()){throw new RuntimeException("BookStack bridge configuration was not loaded");} echo "BookStack bridge is configured".PHP_EOL;'

echo "BookStack bridge provisioned. Admin=$admin_email Book=$book_id"
