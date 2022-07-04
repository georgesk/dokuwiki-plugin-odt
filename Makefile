all:

release:
	date=$$(sed -n 's/^date\s*\(.*\)/\1/ p' plugin.info.txt); \
	zipname=/tmp/dokuwiki-plugin-odt-$${date}.zip; \
	rm -f $${zipname}; \
	cd ..; zip -r $${zipname} dokuwiki-plugin-odt; \
	echo released $${zipname}
