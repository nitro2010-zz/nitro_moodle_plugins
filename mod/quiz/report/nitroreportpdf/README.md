INFO
=============
NitroReportPDF is module for Moodle, which allows on generate PDF file, also XLS, XLSX and ZIP files with the data about conducted the quiz.
Author: Jarosław Maciejewski <nitro.bystrzyca@gmail.com>

TESTING VERSION FOR PHP 7


INSTALLATION
=============
1. Download module ZIP file
2. Unpack
3. Copy 'nitroreportpdf' folder to [moodle directory] /mod/quiz/report
4. change permissions recursively for 'nitroreportpdf' folder to 0755
5. change permissions recursively for 'nitroreportpdf/cache' to 0777
6. run in browser WWW your Moodle website and complete installation
7. fill field 'contact' to you, you can fill also extra declaration, which will be on the end of PDF file


UPGRADE
=============
1. Go to http://yourmoodlesite.com/admin/settings.php?section=modsettingsquizcatnitroreportpdf&action=checkupdate and in section 'Check updates' check whether apppears text 'Is new version! Upgrade!'.
2. If you see that, download file: https://github.com/nitro2010/nitro_moodle_plugins/archive/master.zip 
3. Unpack ZIP file
4. On web hosting delete folder 'nitroreportpdf' in [moodle directory]/mod/quiz/report
5. From unpacked ZIP file, from 'nitro_moodle_plugins-master/mod/quiz/report' upload folder 'nitroreportpdf' to server to [moodle directory]/mod/quiz/report/
6. Change permissions recursively for 'nitroreportpdf' folder to 0755
7. Change permissions recursively for 'nitroreportpdf/cache' to 0777
8. Run in browser WWW your Moodle website and complete installation


LICENSE
=============
GPL 3 or later

NO WARRANTY

THIS PROGRAM IS DISTRIBUTED IN THE HOPE THAT IT WILL BE USEFUL, BUT WITHOUT ANY WARRANTY. IT IS PROVIDED "AS IS" WITHOUT WARRANTY OF ANY KIND, EITHER EXPRESSED OR IMPLIED, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE. THE ENTIRE RISK AS TO THE QUALITY AND PERFORMANCE OF THE PROGRAM IS WITH YOU. SHOULD THE PROGRAM PROVE DEFECTIVE, YOU ASSUME THE COST OF ALL NECESSARY SERVICING, REPAIR OR CORRECTION.

IN NO EVENT THE AUTHOR WILL NOT BE LIABLE TO YOU FOR DAMAGES, INCLUDING ANY GENERAL, SPECIAL, INCIDENTAL OR CONSEQUENTIAL DAMAGES ARISING OUT OF THE USE OR INABILITY TO USE THE PROGRAM (INCLUDING BUT NOT LIMITED TO LOSS OF DATA OR DATA BEING RENDERED INACCURATE OR LOSSES SUSTAINED BY YOU OR THIRD PARTIES OR A FAILURE OF THE PROGRAM TO OPERATE WITH ANY OTHER PROGRAMS), EVEN IF THE AUTHOR HAS BEEN ADVISED OF THE POSSIBILITY OF SUCH DAMAGES.

AUTHOR PROHIBITS THE SALE OF THIS SOFTWARE.


SUPPORT
=============
If you notice errors, please create a new issue on GitHub.