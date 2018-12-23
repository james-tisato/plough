select
     p.Name
    ,bab.Matches as StartMatches
    ,bac.Matches as CurrentMatches
    ,bab.Runs as StartRuns
    ,bac.Runs as CurrentRuns
    ,m.State
    ,m.Type
    ,m.Description
from Player p
left join CareerBattingSummaryBase bab on bab.PlayerId = p.PlayerId
inner join CareerBattingSummary bac on bac.PlayerId = p.PlayerId
inner join Milestone m on m.PlayerId = p.PlayerId
where 1=1
    --and p.Name like '%Tisato%'
order by m.State, m.Type, p.Name