#!/bin/sh

usage="$(basename "$0") [-h] [-u user] [-p password] [-d database]
example:
    bin/truncate-course-table.sh -d www.esdev.com
where:
    -h  show this help text
    -u  set the database user (default: root)
    -d  set the database
    -p  set the database password (default: null)"

database_name=
user="root"
password=

while getopts ':hupd:' option; do
  case "$option" in
    h) echo "$usage"
       exit
       ;;
    u) user=$OPTARG
       ;;
    p) password=$OPTARG   
       ;;
    d) database_name=$OPTARG
       ;;
    :) printf "missing argument for -%s\n" "$OPTARG" >&2
       echo "$usage" >&2
       exit 1
       ;;   
   \?) printf "illegal option: -%s\n" "$OPTARG" >&2
       echo "$usage" >&2
       exit 1
       ;;
  esac
done
shift $((OPTIND - 1))


if [ -n "${password}" ] ; then
    tables=$(mysql -u${user} -p${password} ${database_name} -Nse 'show tables')
else
    tables=$(mysql -u${user} ${database_name} -Nse 'show tables')
fi

not_truncate_tables=(
    "user"
    "user_profile"
    "setting"
    "app"
    "cloud_app"
    "cloud_app_logs"
    "cloud_data"
    "user_active_log"
    "user_approval"
    "user_bind"
    "user_field"
    "user_fortune_log"
    "user_pay_agreement"
    "user_secure_question"
    "user_token"
    "sessions"
    "block"
    "block_history"
    "block_template"
    "tag"
    "tag_group"
    "tag_group_tag"
    "tag_owner"
    "role"
    "org"
    "navigation"
    "migrations"
)

readonly need_truncate_tables

for table in ${tables[@]}
do
    if [[ " ${not_truncate_tables[*]} " != *" ${table} "* ]]; then
        if [ -n "${password}" ] ; then
            $(mysql -u${user} -p${password} -e "truncate table ${table}" ${database_name})
        else
            $(mysql -u${user} -e "truncate table ${table}" ${database_name})
        fi
       
    fi
done