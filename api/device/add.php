<?
${basename(__FILE__, '.php')} = function () {
    ?>
    <h1>Device Add</h1>
    <form action="/api/device/add" method="post">
        <input type="text" name="name" placeholder="Name">
        <input type="text" name="type" placeholder="Type">
        <input type="text" name="serial" placeholder="Serial">
        <input type="text" name="location" placeholder="Location">
        <input type="text" name="status" placeholder="Status">
        <input type="text" name="description" placeholder="Description">
        <input type="submit" value="Add">
    </form>
    
    <?
};