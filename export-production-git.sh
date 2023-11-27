#!/bin/bash

pwd=$(pwd)
project=$(basename $pwd)
echo ----PROJECT $project

cd ~/$project
echo ----UPDATING 

git pull
git status
git commit -a -m 'See changelogs per file'
git push


#for server in srv3.adrussia.ru srv2.adrussia.ru
for server in srv2.adrussia.ru
do
  echo ----EXPORTING TO SERVER $server
  #ssh svn@$server "cd $project && git pull && git checkout-index -a -f --prefix=/www/src/$project/"
done

