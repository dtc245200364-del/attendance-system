$ErrorActionPreference='Stop'
$selector='{job="attendance",service=~"$service",level=~"$level"}'
$panels=@()
$definitions=@(
    @{title='Service logs';expr=$selector;type='logs';x=0;y=0;w=24;h=9},
    @{title='Nginx HTTP 404';expr='{job="attendance",service="nginx"} | json | status = 404';type='logs';x=0;y=9;w=12;h=8},
    @{title='PHP application logs';expr='{job="attendance",service="php",level=~"$level"}';type='logs';x=12;y=9;w=12;h=8},
    @{title='Log entries per service (rolling 5 minutes)';expr='sum by (service) (count_over_time({job="attendance",service=~"$service"}[5m]))';type='timeseries';x=0;y=17;w=24;h=8}
)
$id=0
foreach ($item in $definitions) {
    $id++
    $panels+=@{id=$id;title=$item.title;type=$item.type;datasource=@{type='loki';uid='attendance-loki'};gridPos=@{x=$item.x;y=$item.y;w=$item.w;h=$item.h};targets=@(@{refId='A';expr=$item.expr;queryType='range';datasource=@{type='loki';uid='attendance-loki'};legendFormat='{{service}}'});options=@{showTime=$true;showLabels=$false;wrapLogMessage=$true;sortOrder='Descending'};fieldConfig=@{defaults=@{};overrides=@()}}
}
$dashboard=@{uid='attendance-logs';title='Attendance System Logs';schemaVersion=39;version=1;editable=$false;tags=@('attendance','loki','phase4');timezone='browser';refresh='10s';time=@{from='now-15m';to='now'};panels=$panels;templating=@{list=@(
    @{name='service';label='Service';type='query';datasource=@{type='loki';uid='attendance-loki'};query='label_values({job="attendance"}, service)';refresh=1;includeAll=$true;allValue='.*';multi=$true;current=@{text='All';value='$__all'}},
    @{name='level';label='Level';type='custom';query='info,warn,error,System,Warning,Error,Note';includeAll=$true;allValue='.*';multi=$true;current=@{text='All';value='$__all'}}
)}}
$path=Join-Path (Split-Path $PSScriptRoot -Parent) 'grafana/dashboards/attendance-logs.json'
[IO.File]::WriteAllText($path,($dashboard | ConvertTo-Json -Depth 20),(New-Object Text.UTF8Encoding($false)))
Write-Output 'Generated log dashboard (no embedded log data).'
