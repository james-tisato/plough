select
     p.Name
    ,bac.Matches as StartMatches
    ,bas.Matches as SeasonMatches
    ,bac.Runs as StartRuns
    ,bas.Runs as SeasonRuns
    ,m.State
    ,m.Type
    ,m.Description
from Player p
left join CareerBattingSummary bac on bac.PlayerId = p.PlayerId
inner join BattingSummary bas on bas.PlayerId = p.PlayerId
inner join Milestone m on m.PlayerId = p.PlayerId
where 1=1
    --and p.Name like '%Tisato%'
    --and (m.Type = 'Batting' or m.Type = 'General')
order by m.State, m.Type, p.Name