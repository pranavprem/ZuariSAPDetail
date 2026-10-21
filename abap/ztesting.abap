FUNCTION ztesting.
*"----------------------------------------------------------------------
*"*"Local Interface:
*"  TABLES
*"      TEMPO STRUCTURE PA0001
*"----------------------------------------------------------------------
*" Reference copy of function module ZTESTING.
*"
*" Create it in SE37 as a remote-enabled function module. The PHP page
*" calls ZTESTING over RFC and reads PERNR from the TEMPO rows. This file
*" is documentation for that module; PHP does not execute it.
*"
*" PA0001 is infotype 0001 (Organizational Assignment). begda and endda are
*" inclusive validity dates, so an assignment that starts or ends today is
*" current. The 2014 version compared with strict inequalities and dropped
*" those boundary rows.

  DATA date LIKE sy-datum.

  date = sy-datum.

  SELECT pernr
    FROM pa0001
    INTO CORRESPONDING FIELDS OF TABLE tempo
    WHERE begda <= date
      AND endda >= date.

ENDFUNCTION.
